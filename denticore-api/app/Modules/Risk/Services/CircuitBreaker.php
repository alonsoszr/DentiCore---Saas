<?php

namespace App\Modules\Risk\Services;

use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Circuit Breaker del motor ML en Redis (SDD §4.6; DI-12, RF-166, RN-64). Las transiciones son
 * atómicas con scripts Lua sobre `cb:ml:state`, `cb:ml:failures` y `cb:ml:opened_at`:
 *
 * - cerrado: deja pasar; 5 fallos consecutivos lo abren.
 * - abierto: durante 60 s rechaza sin llamar al motor.
 * - semiabierto: pasados los 60 s, una sola sonda (bloqueo `cb:ml:probe`); su éxito lo cierra y
 *   su fallo lo reabre por otros 60 s.
 *
 * Si Redis no responde, el breaker se considera cerrado y rige solo el timeout (DI-12). El reloj
 * se pasa como argumento para que las pruebas lo controlen (T-161).
 */
class CircuitBreaker
{
    public const CLOSED = 'cerrado';

    public const OPEN = 'abierto';

    public const HALF_OPEN = 'semiabierto';

    /** Resultado de `acquire()` cuando la llamada no debe hacerse. */
    public const REJECTED = 'rechazada';

    private const ACQUIRE = <<<'LUA'
        local state = redis.call('GET', KEYS[1]) or 'cerrado'
        if state == 'cerrado' then return 'cerrado' end
        local opened = tonumber(redis.call('GET', KEYS[3]) or '0')
        if state == 'abierto' and tonumber(ARGV[1]) - opened < tonumber(ARGV[2]) then return 'rechazada' end
        if redis.call('SET', KEYS[4], '1', 'NX', 'EX', ARGV[3]) then
          redis.call('SET', KEYS[1], 'semiabierto')
          return 'semiabierto'
        end
        return 'rechazada'
        LUA;

    private const FAILURE = <<<'LUA'
        local state = redis.call('GET', KEYS[1]) or 'cerrado'
        local failures = redis.call('INCR', KEYS[2])
        if state == 'semiabierto' or failures >= tonumber(ARGV[2]) then
          redis.call('SET', KEYS[1], 'abierto')
          redis.call('SET', KEYS[3], ARGV[1])
          redis.call('SET', KEYS[2], 0)
          redis.call('DEL', KEYS[4])
          return 'abierto'
        end
        return state
        LUA;

    private const SUCCESS = <<<'LUA'
        redis.call('SET', KEYS[1], 'cerrado')
        redis.call('SET', KEYS[2], 0)
        redis.call('DEL', KEYS[3], KEYS[4])
        return 'cerrado'
        LUA;

    private const KEYS = ['cb:ml:state', 'cb:ml:failures', 'cb:ml:opened_at', 'cb:ml:probe'];

    public function __construct(private readonly MlSettings $settings) {}

    /**
     * Decide si se llama al motor: `cerrado` o `semiabierto` (la sonda) sí; `rechazada` no.
     */
    public function acquire(): string
    {
        // La sonda bloquea a las demás mientras dura una llamada completa.
        $probeTtl = (int) ceil($this->settings->timeoutMs / 1000) + MlSettings::CONNECT_TIMEOUT_SECONDS + 1;

        return $this->run(self::ACQUIRE, [now()->getTimestamp(), $this->settings->breakerOpenSeconds, $probeTtl]) ?? self::CLOSED;
    }

    /** Registra un fallo y devuelve el estado resultante. */
    public function recordFailure(): string
    {
        return $this->run(self::FAILURE, [now()->getTimestamp(), $this->settings->breakerFailures]) ?? self::CLOSED;
    }

    public function recordSuccess(): string
    {
        return $this->run(self::SUCCESS, []) ?? self::CLOSED;
    }

    /** Estado actual, para el registro de llamadas. */
    public function state(): string
    {
        try {
            $state = Redis::connection()->get(self::KEYS[0]);
        } catch (Throwable) {
            return self::CLOSED;
        }

        return is_string($state) ? $state : self::CLOSED;
    }

    /** Vuelve al estado inicial (pruebas y operación). */
    public function reset(): void
    {
        try {
            Redis::connection()->del(...self::KEYS);
        } catch (Throwable) {
            // Sin Redis no hay estado que limpiar.
        }
    }

    /**
     * @param  list<int>  $arguments
     */
    private function run(string $script, array $arguments): ?string
    {
        try {
            $connection = Redis::connection();
            // PhpRedisConnection normaliza la firma de EVAL; con predis se envía el comando tal cual.
            $result = $connection instanceof PhpRedisConnection
                ? $connection->eval($script, count(self::KEYS), ...self::KEYS, ...$arguments)
                : $connection->command('eval', [$script, count(self::KEYS), ...self::KEYS, ...$arguments]);
        } catch (Throwable $exception) {
            Log::warning('ml.circuit_breaker.redis_unavailable', ['error' => $exception->getMessage()]);

            return null;
        }

        return is_string($result) ? $result : null;
    }
}
