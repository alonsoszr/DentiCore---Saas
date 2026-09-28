<?php

namespace App\Support\Tenancy;

use Closure;

/**
 * Job middleware de SDD §1.6.2 y §5.15: un *job* de clínica serializa `tenantId`; este
 * middleware fija TenantContext (y `app.tenant_id`) antes de handle() y lo limpia al
 * terminar. Sin `tenantId` el *job* falla sin leer ni escribir datos.
 *
 * Uso en el job: `public ?int $tenantId` y `middleware(): [new TenantAwareJob]`.
 */
class TenantAwareJob
{
    /**
     * @param  Closure(object): mixed  $next
     *
     * @throws MissingTenantContextException
     */
    public function handle(object $job, Closure $next): mixed
    {
        $tenantId = property_exists($job, 'tenantId') ? $job->tenantId : null;

        if (! is_int($tenantId)) {
            throw new MissingTenantContextException;
        }

        return TenantContext::run($tenantId, fn () => $next($job));
    }
}
