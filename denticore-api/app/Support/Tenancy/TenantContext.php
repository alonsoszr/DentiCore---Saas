<?php

namespace App\Support\Tenancy;

use App\Modules\Platform\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Clínica activa de la solicitud, del *job* o del lote programado (SDD §1.6.2, §1.6.3).
 *
 * El estado vive en el contenedor, así que se reinicia con cada aplicación (solicitud o
 * prueba). Fijar la clínica también fija `app.tenant_id` en la sesión de PostgreSQL, la
 * variable que usan las políticas RLS (DI-10).
 */
final class TenantContext
{
    private const BINDING = 'tenancy.current';

    public static function set(Tenant $tenant): void
    {
        app()->instance(self::BINDING, $tenant);
        self::applyDatabaseSetting((string) $tenant->id);
    }

    public static function clear(): void
    {
        app()->forgetInstance(self::BINDING);
        self::applyDatabaseSetting('');
    }

    public static function tenant(): ?Tenant
    {
        return app()->bound(self::BINDING) ? app(self::BINDING) : null;
    }

    public static function id(): ?int
    {
        return self::tenant()?->id;
    }

    /**
     * @throws MissingTenantContextException
     */
    public static function idOrFail(): int
    {
        return self::id() ?? throw new MissingTenantContextException;
    }

    /**
     * @throws MissingTenantContextException
     */
    public static function tenantOrFail(): Tenant
    {
        return self::tenant() ?? throw new MissingTenantContextException;
    }

    /**
     * Ejecuta `$callback` con la clínica indicada y restaura la anterior al terminar.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function run(Tenant|int $tenant, Closure $callback): mixed
    {
        $previous = self::tenant();

        // Ya es la clínica del contexto: nada que fijar ni restaurar. Evita consultas de más y que
        // la restauración corra dentro de una transacción ya abortada y oculte su error.
        if ($previous !== null && $previous->id === ($tenant instanceof Tenant ? $tenant->id : $tenant)) {
            return $callback();
        }

        self::set($tenant instanceof Tenant ? $tenant : Tenant::query()->findOrFail($tenant));
        $restore = fn () => $previous ? self::set($previous) : self::clear();

        try {
            $result = $callback();
        } catch (Throwable $exception) {
            // Si la consulta abortó la transacción, restaurar la variable de sesión también falla
            // (25P02) y ocultaría el error real; la reversión de la transacción la deshace igual.
            try {
                $restore();
            } catch (Throwable) {
            }

            throw $exception;
        }

        $restore();

        return $result;
    }

    private static function applyDatabaseSetting(string $tenantId): void
    {
        DB::select("select set_config('app.tenant_id', ?, false)", [$tenantId]);
    }
}
