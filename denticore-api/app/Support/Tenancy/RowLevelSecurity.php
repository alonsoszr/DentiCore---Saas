<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Seguridad por fila como segunda barrera (SDD §2.13; DD-40, DI-10, RNF-102). Cada tabla
 * de clínica habilita y fuerza RLS con la política `tenant_isolation`, que compara
 * tenant_id con `app.tenant_id` (fijado por TenantContext). Sin esa variable, el rol
 * `denticore_app` no ve filas.
 *
 * Se usa en las migraciones como `Schema::enableTenantRls('tabla')` (macro registrada en
 * AppServiceProvider).
 */
final class RowLevelSecurity
{
    public static function enable(string $table): void
    {
        $quoted = DB::getQueryGrammar()->wrapTable($table);

        DB::statement("ALTER TABLE {$quoted} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$quoted} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$quoted}");
        DB::statement(<<<SQL
            CREATE POLICY tenant_isolation ON {$quoted}
              USING (tenant_id = nullif(current_setting('app.tenant_id', true), '')::bigint)
              WITH CHECK (tenant_id = nullif(current_setting('app.tenant_id', true), '')::bigint)
            SQL);
    }

    public static function disable(string $table): void
    {
        $quoted = DB::getQueryGrammar()->wrapTable($table);

        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$quoted}");
        DB::statement("ALTER TABLE {$quoted} NO FORCE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$quoted} DISABLE ROW LEVEL SECURITY");
    }
}
