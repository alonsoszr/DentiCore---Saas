<?php

namespace App\Support\Http;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Middleware `idempotent` (SDD §1.7, §4.2; DD-45, RNF-079). En las rutas marcadas exige la
 * cabecera `Idempotency-Key` (UUID) y guarda 24 h la respuesta por (subject, key):
 * - misma clave y mismo cuerpo → misma respuesta, sin volver a ejecutar la operación;
 * - misma clave y cuerpo distinto → 422;
 * - sin cabecera → 400.
 * `subject` es el usuario autenticado o el hash del token público de la ruta.
 */
class HandleIdempotencyKey
{
    public const HEADER = 'Idempotency-Key';

    public const TTL_HOURS = 24;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get(self::HEADER);

        if (! is_string($key) || ! Str::isUuid($key)) {
            throw new BadRequestHttpException('Falta la cabecera Idempotency-Key con un UUID.');
        }

        $subject = $this->subject($request);
        $requestHash = $this->requestHash($request);

        $stored = IdempotencyKey::query()
            ->where('subject', $subject)
            ->where('key', $key)
            ->where('expires_at', '>', now())
            ->first();

        if ($stored !== null) {
            return $this->replay($stored, $requestHash);
        }

        try {
            // Reserva la clave antes de ejecutar (en un savepoint): un reintento simultáneo
            // recibe 409. Una clave vencida se reemplaza.
            $reservation = DB::transaction(function () use ($request, $subject, $key, $requestHash): IdempotencyKey {
                IdempotencyKey::query()->where('subject', $subject)->where('key', $key)->where('expires_at', '<=', now())->delete();

                return IdempotencyKey::query()->create([
                    'subject' => $subject,
                    'key' => $key,
                    'request_hash' => $requestHash,
                    'method' => $request->method(),
                    'route_name' => $request->route()?->getName() ?? $request->route()?->uri(),
                    'expires_at' => now()->addHours(self::TTL_HOURS),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConflictHttpException('Hay una solicitud en curso con la misma Idempotency-Key.');
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $reservation->delete();

            throw $exception;
        }

        // Las respuestas 5xx no se guardan: la operación puede reintentarse con la misma clave.
        if ($response->getStatusCode() >= 500) {
            $reservation->delete();

            return $response;
        }

        $reservation->forceFill([
            'response_status' => $response->getStatusCode(),
            'response_body' => json_decode((string) $response->getContent(), true),
        ])->save();

        return $response;
    }

    private function replay(IdempotencyKey $stored, string $requestHash): Response
    {
        if (! hash_equals($stored->request_hash, $requestHash)) {
            return ProblemDetails::from(new BusinessRuleException(
                'DD-45',
                'La Idempotency-Key ya se usó con otra solicitud.',
                [self::HEADER => ['Use una clave nueva para una solicitud distinta.']],
            ));
        }

        if ($stored->response_status === null) {
            throw new ConflictHttpException('Hay una solicitud en curso con la misma Idempotency-Key.');
        }

        return new JsonResponse($stored->response_body, $stored->response_status, ['Idempotent-Replayed' => 'true']);
    }

    private function subject(Request $request): string
    {
        if ($request->user() !== null) {
            return 'user:'.$request->user()->getAuthIdentifier();
        }

        $token = $request->route('token');

        if (is_string($token) && $token !== '') {
            return 'token:'.hash('sha256', $token);
        }

        // SDD §2.12: el sujeto es el usuario o el token público; otra ruta no puede ser idempotent.
        throw new LogicException('La ruta idempotent no tiene usuario autenticado ni token público.');
    }

    private function requestHash(Request $request): string
    {
        $body = $request->all();
        ksort($body);

        return hash('sha256', $request->method().' '.$request->path().' '.json_encode($body));
    }
}
