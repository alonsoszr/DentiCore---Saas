<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Expansión de `encryption_keys` (TASK-013; SDD §2.3, DD-04, RF-048, RNF-132): claves
 * versionadas con estado. Cada clave existente queda como versión 1 `activa` (o `retirada`
 * si estaba inactiva). La contracción (`is_active` y el UNIQUE(tenant_id) total) va en
 * TASK-038.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE encryption_keys ADD COLUMN version smallint;
            ALTER TABLE encryption_keys ADD COLUMN status varchar(20);
            ALTER TABLE encryption_keys ADD COLUMN retired_at timestamptz;

            UPDATE encryption_keys
               SET version = 1,
                   status = CASE WHEN is_active THEN 'activa' ELSE 'retirada' END
             WHERE version IS NULL;

            ALTER TABLE encryption_keys ALTER COLUMN version SET NOT NULL;
            ALTER TABLE encryption_keys ALTER COLUMN status SET NOT NULL;
            ALTER TABLE encryption_keys ADD CONSTRAINT encryption_keys_status_check
                CHECK (status IN ('activa', 'rotando', 'retirada'));
            ALTER TABLE encryption_keys ADD CONSTRAINT encryption_keys_tenant_version_unique
                UNIQUE (tenant_id, version);
            CREATE UNIQUE INDEX encryption_keys_tenant_active_unique
                ON encryption_keys (tenant_id) WHERE status = 'activa';
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS encryption_keys_tenant_active_unique;
            ALTER TABLE encryption_keys DROP CONSTRAINT IF EXISTS encryption_keys_tenant_version_unique;
            ALTER TABLE encryption_keys DROP CONSTRAINT IF EXISTS encryption_keys_status_check;
            ALTER TABLE encryption_keys DROP COLUMN IF EXISTS retired_at;
            ALTER TABLE encryption_keys DROP COLUMN IF EXISTS status;
            ALTER TABLE encryption_keys DROP COLUMN IF EXISTS version;
            SQL);
    }
};
