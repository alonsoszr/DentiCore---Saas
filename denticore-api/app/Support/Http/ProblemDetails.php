<?php

namespace App\Support\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Errores de la API como `application/problem+json` (RFC 9457; SDD §1.7, §4.1; RF-008,
 * DD-19): `type`, `title`, `status`, `detail` en español, `instance` con el id de
 * correlación y, en 422, `errors: {campo: [motivo]}`. Nunca expone trazas ni SQL.
 */
final class ProblemDetails
{
    private const TYPE_BASE = 'https://denticore.pe/problems/';

    /**
     * @var list<class-string<Throwable>>
     */
    private const OWN_HTTP_EXCEPTIONS = [HttpException::class, BadRequestHttpException::class, ConflictHttpException::class];

    /**
     * Tipo, título y detalle por estado HTTP.
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private const BY_STATUS = [
        400 => ['bad-request', 'Solicitud no válida', 'La solicitud no tiene el formato esperado.'],
        401 => ['unauthenticated', 'No autenticado', 'Inicie sesión para continuar.'],
        403 => ['forbidden', 'Acceso denegado', 'No tiene permiso para realizar esta acción.'],
        404 => ['not-found', 'Recurso no encontrado', 'El recurso solicitado no existe.'],
        405 => ['method-not-allowed', 'Método no permitido', 'La operación no está disponible para este recurso.'],
        409 => ['conflict', 'Conflicto', 'La operación entra en conflicto con el estado actual del recurso.'],
        422 => ['validation', 'Datos no válidos', 'Revise los datos enviados.'],
        429 => ['too-many-requests', 'Demasiadas solicitudes', 'Se superó el límite de solicitudes. Espere e inténtelo nuevamente.'],
        500 => ['server-error', 'Error interno', 'Ocurrió un error inesperado. Inténtelo nuevamente.'],
    ];

    public static function from(Throwable $exception): JsonResponse
    {
        [$status, $headers] = self::statusAndHeaders($exception);
        [$type, $title, $detail] = self::BY_STATUS[$status] ?? [
            'http-error', 'Error', 'No se pudo completar la solicitud.',
        ];

        $body = [
            'type' => self::TYPE_BASE.$type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => 'urn:correlation:'.(CorrelationId::current() ?? 'desconocido'),
        ];

        if ($exception instanceof BusinessRuleException) {
            $body['type'] = self::TYPE_BASE.'business-rule';
            $body['title'] = 'Regla de negocio incumplida';
            $body['detail'] = $exception->getMessage();
            $body['rule'] = $exception->rule;

            if ($exception->errors !== []) {
                $body['errors'] = $exception->errors;
            }
        }

        if ($exception instanceof ValidationException) {
            $body['errors'] = $exception->errors();
        }

        // Mensajes en español de las excepciones HTTP que lanza el código de la API (p. ej.
        // "Credenciales inválidas." de RF-033). Las del framework traen mensajes en inglés y
        // conservan el detalle genérico.
        if (in_array($exception::class, self::OWN_HTTP_EXCEPTIONS, true) && $exception->getMessage() !== '') {
            $body['detail'] = $exception->getMessage();
        }

        return new JsonResponse($body, $status, [...$headers, 'Content-Type' => 'application/problem+json']);
    }

    /**
     * @return array{0: int, 1: array<string, string>}
     */
    private static function statusAndHeaders(Throwable $exception): array
    {
        return match (true) {
            $exception instanceof BusinessRuleException => [$exception->status, []],
            $exception instanceof ValidationException => [422, []],
            $exception instanceof AuthenticationException => [401, []],
            $exception instanceof AuthorizationException => [403, []],
            $exception instanceof ModelNotFoundException => [404, []],
            $exception instanceof ThrottleRequestsException => [429, self::stringHeaders($exception->getHeaders())],
            $exception instanceof HttpExceptionInterface => [$exception->getStatusCode(), self::stringHeaders($exception->getHeaders())],
            default => [500, []],
        };
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private static function stringHeaders(array $headers): array
    {
        return array_map(fn (mixed $value): string => is_array($value) ? implode(', ', $value) : (string) $value, $headers);
    }
}
