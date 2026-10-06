<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Contracción de las columnas heredadas (TASK-038; Plan §1.5, RNF-132, DI-03). Todo el código
 * usa ya las columnas nuevas de las etapas de expansión:
 *
 * - `tenants`: se eliminan `subscription_plan` (ahora `subscription_plan_id`) y `settings` (ahora
 *   `clinic_settings`); `legal_name`, `ruc` y `address` pasan a NOT NULL (SDD §2.3). El `status`
 *   en inglés ya se convirtió en el mismo lugar en TASK-021.
 * - `users`: se elimina `is_active` (ahora `status`).
 * - `encryption_keys`: se eliminan `is_active` y el UNIQUE(tenant_id) total; queda el índice
 *   único parcial de la clave `activa` (SDD §2.3).
 * - `patients`: se eliminan `document_id` y `document_id_hash`; la identificación de SDD §2.5
 *   (documento, HC, `search_name`, `sex`, `created_by`) pasa a NOT NULL.
 *
 * **No es reversible**: los datos de las columnas eliminadas no se pueden reconstruir. Las fichas
 * pobladas desde el esquema heredado sin `sex` ni `created_by` impiden aplicarla; como solo hay
 * datos sintéticos (RES-08), se regeneran con `migrate:fresh --seed`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE tenants
                DROP CONSTRAINT IF EXISTS tenants_subscription_plan_check,
                DROP COLUMN subscription_plan,
                DROP COLUMN settings,
                ALTER COLUMN legal_name SET NOT NULL,
                ALTER COLUMN ruc SET NOT NULL,
                ALTER COLUMN address SET NOT NULL;

            ALTER TABLE users DROP COLUMN is_active;

            ALTER TABLE encryption_keys
                DROP CONSTRAINT IF EXISTS encryption_keys_tenant_id_unique,
                DROP COLUMN is_active;

            ALTER TABLE patients
                DROP CONSTRAINT IF EXISTS patients_tenant_id_document_id_hash_unique,
                DROP COLUMN document_id,
                DROP COLUMN document_id_hash,
                ALTER COLUMN document_type SET NOT NULL,
                ALTER COLUMN document_number SET NOT NULL,
                ALTER COLUMN document_hash SET NOT NULL,
                ALTER COLUMN clinical_record_number SET NOT NULL,
                ALTER COLUMN clinical_record_hash SET NOT NULL,
                ALTER COLUMN search_name SET NOT NULL,
                ALTER COLUMN sex SET NOT NULL,
                ALTER COLUMN created_by SET NOT NULL;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('La contracción de TASK-038 no es reversible: use migrate:fresh --seed (datos sintéticos).');
    }
};
