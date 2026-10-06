<?php

use App\Support\Http\CorrelationId;
use App\Support\Http\EnsureRole;
use App\Support\Http\HandleIdempotencyKey;
use App\Support\Http\ProblemDetails;
use App\Support\Http\SecurityHeaders;
use App\Support\Tenancy\AllowCancelledExport;
use App\Support\Tenancy\AllowInReadOnlyTenant;
use App\Support\Tenancy\EnsurePlanFeature;
use App\Support\Tenancy\EnsureTenantWritable;
use App\Support\Tenancy\ResolveTenant;
use App\Support\Tenancy\ResolveTenantBySlug;
use App\Support\Tenancy\ResolveTenantByToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Support/Outbox/Commands',
        __DIR__.'/../app/Support/Encryption/Commands',
        __DIR__.'/../app/Support/Audit/Commands',
        __DIR__.'/../app/Support/Http/Commands',
        __DIR__.'/../app/Support/Evidence/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Alias de SDD §4.2.
        $middleware->alias([
            'role' => EnsureRole::class,
            'tenant' => ResolveTenant::class,
            'idempotent' => HandleIdempotencyKey::class,
            'tenant.token' => ResolveTenantByToken::class,
            'tenant.slug' => ResolveTenantBySlug::class,
            'tenant.writable' => EnsureTenantWritable::class,
            'tenant.readonly_ok' => AllowInReadOnlyTenant::class,
            'tenant.exportable' => AllowCancelledExport::class,
            'plan.feature' => EnsurePlanFeature::class,
        ]);

        // Globales: id de correlación primero, para que todo lo demás (incluidos los errores) lo
        // use, y cabeceras de seguridad de SDD §1.7 en toda respuesta, también en las de error.
        $middleware->prepend([CorrelationId::class, SecurityHeaders::class]);

        // El route model binding (SubstituteBindings) debe correr con el tenant ya
        // resuelto: si no, el Global Scope niega todo y cada {patient} daría 404.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenantByToken::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenantBySlug::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Toda respuesta de error de la API es application/problem+json (SDD §4.1, RF-008).
        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($request->is('api/*')) {
                $response = ProblemDetails::from($exception);
                $response->headers->set(CorrelationId::HEADER, CorrelationId::current() ?? '');

                return $response;
            }

            return null;
        });
    })->create();
