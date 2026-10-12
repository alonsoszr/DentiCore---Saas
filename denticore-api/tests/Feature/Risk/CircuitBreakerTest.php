<?php

/*
 * Circuit Breaker del motor ML (TASK-080; SDD §4.6; DI-12, RF-166, RN-64; T-129): 5 fallos
 * consecutivos lo abren 60 s, después deja pasar una sola sonda y, sin Redis, rige solo el timeout.
 */

use App\Modules\Risk\Models\ExternalCallLog;
use App\Modules\Risk\Services\CircuitBreaker;
use App\Modules\Risk\Services\RiskEngineClient;
use App\Modules\Risk\Services\RiskEngineUnavailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\Support\ExternalServices;
use Tests\Support\MlEngine;

beforeEach(fn () => app(CircuitBreaker::class)->reset());
afterEach(fn () => app(CircuitBreaker::class)->reset());

/** Reconstruye la conexión de Redis con otro puerto (1 = Redis detenido). */
function redisOn(int $port): void
{
    config(['database.redis.default.port' => $port, 'database.redis.default.max_retries' => 0]);
    app()->forgetInstance('redis');
    Redis::clearResolvedInstance('redis');
}

/** Resultado de una predicción: `ok` o el `outcome` de la indisponibilidad. */
function enginePredict(): string
{
    try {
        app(RiskEngineClient::class)->predict('1.0.0', 'adulto', MlEngine::features());
    } catch (RiskEngineUnavailable $unavailable) {
        return $unavailable->outcome;
    }

    return 'ok';
}

it('answers unavailable without calling the engine after 5 consecutive failures', function () {
    ExternalServices::timesOut(MlEngine::URL);

    $outcomes = array_map(fn () => enginePredict(), range(1, 6));

    expect($outcomes)->toBe(['timeout', 'timeout', 'timeout', 'timeout', 'timeout', 'cb_abierto'])
        ->and(app(CircuitBreaker::class)->state())->toBe('abierto')
        ->and(ExternalCallLog::query()->where('outcome', 'cb_abierto')->value('breaker_state'))->toBe('abierto');
    Http::assertSentCount(5);
})->group('T-129', 'CA-55.5', 'RN-64', 'RF-166');

it('lets a single probe through after 60 seconds and closes on success', function () {
    ExternalServices::timesOut(MlEngine::URL);
    foreach (range(1, 5) as $ignored) {
        enginePredict();
    }

    $this->travel(59)->seconds();
    expect(enginePredict())->toBe('cb_abierto');

    $this->travel(2)->seconds();
    MlEngine::reset();
    MlEngine::predicts(0.2);
    expect(enginePredict())->toBe('ok')
        ->and(app(CircuitBreaker::class)->state())->toBe('cerrado');
})->group('RF-166', 'DI-12');

it('reopens the breaker for another 60 seconds when the probe fails', function () {
    ExternalServices::timesOut(MlEngine::URL);
    foreach (range(1, 5) as $ignored) {
        enginePredict();
    }

    $this->travel(61)->seconds();
    expect(enginePredict())->toBe('timeout')
        ->and(app(CircuitBreaker::class)->state())->toBe('abierto');

    $this->travel(30)->seconds();
    expect(enginePredict())->toBe('cb_abierto');
})->group('RF-166', 'DI-12');

it('does not count an out of domain answer as a failure', function () {
    ExternalServices::respondsWith(MlEngine::URL, ['title' => 'out-of-domain'], 422);

    $outcomes = array_map(fn () => enginePredict(), range(1, 6));

    expect(array_unique($outcomes))->toBe(['error'])
        ->and(app(CircuitBreaker::class)->state())->toBe('cerrado');
    Http::assertSentCount(6);
})->group('RNF-174', 'RN-64');

it('considers the breaker closed when Redis is down and relies on the timeout', function () {
    redisOn(1);
    ExternalServices::timesOut(MlEngine::URL);

    $outcomes = array_map(fn () => enginePredict(), range(1, 6));

    expect(array_unique($outcomes))->toBe(['timeout'])
        ->and(ExternalCallLog::query()->pluck('breaker_state')->unique()->all())->toBe(['cerrado']);
    Http::assertSentCount(6);

    redisOn((int) env('REDIS_PORT', 6379));
})->group('DI-12', 'RN-64');

it('logs every call without the request or the response', function () {
    MlEngine::predicts(0.2);

    expect(enginePredict())->toBe('ok');

    $log = ExternalCallLog::query()->sole();
    expect($log->only(['service', 'operation', 'outcome', 'http_status', 'breaker_state']))
        ->toBe(['service' => 'ml', 'operation' => 'predict', 'outcome' => 'ok', 'http_status' => 200, 'breaker_state' => 'cerrado'])
        ->and(array_keys($log->getAttributes()))->not->toContain('payload');
    Http::assertSent(fn ($request) => $request->hasHeader('X-ML-Service-Key') && $request->url() === config('services.ml.url').'/v1/predict');
})->group('RN-64', 'RF-197');
