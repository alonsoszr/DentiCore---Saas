<?php

use App\Modules\Patients\Services\PatientIdentity;
use App\Support\Encryption\TenantEncryption;
use App\Support\Tenancy\RowLevelSecurity;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Esquema de pacientes, etapa de expansión (TASK-031; SDD §2.5, Plan §2.3; DD-04, DI-07, DI-14,
 * DI-19, RN-09, RN-79, RNF-132, RNF-191).
 *
 * - Agrega a `patients` las columnas de SDD §2.5 sin tocar las heredadas (`document_id` y
 *   `document_id_hash` se eliminan en TASK-038), la colación `es-PE-x-icu` de los nombres,
 *   `UNIQUE (tenant_id, id)` y la FK compuesta de `merged_into_patient_id` (DI-19).
 * - Puebla cada ficha existente desde su DNI: `document_type = dni`, `document_number` cifrado
 *   en `v1:`, `document_hash` recalculado con la clave `bidx` sobre `DNI:NUMERO`, número de HC =
 *   DNI y `search_name`. Solo hay datos sintéticos (RES-08): `sex` y `created_by`, sin origen,
 *   quedan nulos hasta que la semilla regenere los datos (NOT NULL en TASK-038).
 * - Crea `patient_identity_history` (BTi, RLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE patients
                ADD COLUMN document_type varchar(10) NULL
                    CONSTRAINT patients_document_type_check CHECK (document_type IN ('dni', 'ce', 'pasaporte', 'cpp')),
                ADD COLUMN document_number text NULL,
                ADD COLUMN document_hash char(64) NULL,
                ADD COLUMN clinical_record_number text NULL,
                ADD COLUMN clinical_record_hash char(64) NULL,
                ADD COLUMN sex varchar(10) NULL
                    CONSTRAINT patients_sex_check CHECK (sex IN ('femenino', 'masculino')),
                ADD COLUMN address text NULL,
                ADD COLUMN search_name varchar(210) NULL,
                ADD COLUMN archive_status varchar(20) NOT NULL DEFAULT 'activo'
                    CONSTRAINT patients_archive_status_check
                    CHECK (archive_status IN ('activo', 'pasivo', 'bloqueado', 'apto_eliminacion', 'fusionado')),
                ADD COLUMN first_attention_at timestamptz NULL,
                ADD COLUMN last_attention_at timestamptz NULL,
                ADD COLUMN deceased_on date NULL,
                ADD COLUMN merged_into_patient_id bigint NULL,
                ADD COLUMN created_by bigint NULL REFERENCES users (id) ON DELETE RESTRICT;

            ALTER TABLE patients ALTER COLUMN first_name TYPE varchar(100) COLLATE "es-PE-x-icu";
            ALTER TABLE patients ALTER COLUMN last_name TYPE varchar(100) COLLATE "es-PE-x-icu";

            ALTER TABLE patients ADD CONSTRAINT patients_birth_date_check CHECK (birth_date >= DATE '1900-01-01');
            ALTER TABLE patients ADD CONSTRAINT patients_tenant_id_id_unique UNIQUE (tenant_id, id);
            ALTER TABLE patients ADD CONSTRAINT patients_merged_into_fk
                FOREIGN KEY (tenant_id, merged_into_patient_id) REFERENCES patients (tenant_id, id) ON DELETE RESTRICT;
            ALTER TABLE patients ADD CONSTRAINT patients_merged_check
                CHECK ((archive_status = 'fusionado') = (merged_into_patient_id IS NOT NULL));
            SQL);

        $this->backfill();

        DB::unprepared(<<<'SQL'
            ALTER TABLE patients ADD CONSTRAINT patients_tenant_document_hash_unique UNIQUE (tenant_id, document_hash);
            ALTER TABLE patients ADD CONSTRAINT patients_tenant_clinical_record_hash_unique UNIQUE (tenant_id, clinical_record_hash);
            CREATE INDEX patients_tenant_archive_name_idx ON patients (tenant_id, archive_status, last_name, first_name);
            CREATE INDEX patients_search_name_trgm_idx ON patients USING gin (search_name gin_trgm_ops);
            CREATE INDEX patients_tenant_last_attention_idx ON patients (tenant_id, last_attention_at);

            -- RN-79, DI-07: el número de historia clínica no cambia una vez asignado.
            CREATE OR REPLACE FUNCTION fn_patients_clinical_record_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF OLD.clinical_record_number IS NOT NULL AND (
                   NEW.clinical_record_number IS DISTINCT FROM OLD.clinical_record_number
                OR NEW.clinical_record_hash IS DISTINCT FROM OLD.clinical_record_hash) THEN
                RAISE EXCEPTION 'immutable_clinical_record: patients.clinical_record_number' USING ERRCODE = '55000';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_patients_clinical_record_immutable
                BEFORE UPDATE OF clinical_record_number, clinical_record_hash ON patients
                FOR EACH ROW EXECUTE FUNCTION fn_patients_clinical_record_immutable();

            CREATE TABLE patient_identity_history (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                patient_id bigint NOT NULL,
                changed_fields jsonb NOT NULL,
                previous_values text NOT NULL,
                changed_by bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (tenant_id, id),
                FOREIGN KEY (tenant_id, patient_id) REFERENCES patients (tenant_id, id) ON DELETE RESTRICT
            );
            CREATE INDEX patient_identity_history_patient_idx
                ON patient_identity_history (tenant_id, patient_id, created_at DESC);
            CREATE TRIGGER trg_forbid_update_delete BEFORE UPDATE OR DELETE ON patient_identity_history
                FOR EACH ROW EXECUTE FUNCTION fn_forbid_update_delete();
            SQL);

        RowLevelSecurity::enable('patient_identity_history');
    }

    /**
     * Cada clínica se procesa en su contexto (RLS forzada) con sus propias claves.
     */
    private function backfill(): void
    {
        $encryption = app(TenantEncryption::class);

        foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
            TenantContext::run((int) $tenantId, function () use ($encryption, $tenantId): void {
                DB::table('patients')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('document_hash')
                    ->orderBy('id')
                    ->chunkById(500, function (Collection $rows) use ($encryption, $tenantId): void {
                        foreach ($rows as $row) {
                            $tenant = (int) $tenantId;
                            $number = PatientIdentity::normalizedNumber($encryption->decrypt($tenant, $row->document_id));
                            $record = PatientIdentity::clinicalRecordNumber('dni', $number);

                            DB::table('patients')->where('id', $row->id)->update([
                                'document_type' => 'dni',
                                'document_number' => $encryption->encrypt($tenant, $number),
                                'document_hash' => $encryption->blindIndex($tenant, PatientIdentity::normalizedDocument('dni', $number)),
                                'clinical_record_number' => $encryption->encrypt($tenant, $record),
                                'clinical_record_hash' => $encryption->blindIndex($tenant, $record),
                                'search_name' => PatientIdentity::searchName($row->first_name, $row->last_name),
                            ]);
                        }
                    });
            });
        }
    }

    public function down(): void
    {
        RowLevelSecurity::disable('patient_identity_history');

        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS patient_identity_history;

            DROP TRIGGER IF EXISTS trg_patients_clinical_record_immutable ON patients;
            DROP FUNCTION IF EXISTS fn_patients_clinical_record_immutable();

            DROP INDEX IF EXISTS patients_tenant_last_attention_idx;
            DROP INDEX IF EXISTS patients_search_name_trgm_idx;
            DROP INDEX IF EXISTS patients_tenant_archive_name_idx;
            ALTER TABLE patients DROP CONSTRAINT IF EXISTS patients_tenant_clinical_record_hash_unique;
            ALTER TABLE patients DROP CONSTRAINT IF EXISTS patients_tenant_document_hash_unique;
            ALTER TABLE patients DROP CONSTRAINT IF EXISTS patients_merged_check;
            ALTER TABLE patients DROP CONSTRAINT IF EXISTS patients_merged_into_fk;
            ALTER TABLE patients DROP CONSTRAINT IF EXISTS patients_tenant_id_id_unique;
            ALTER TABLE patients DROP CONSTRAINT IF EXISTS patients_birth_date_check;

            ALTER TABLE patients ALTER COLUMN first_name TYPE varchar(100) COLLATE "default";
            ALTER TABLE patients ALTER COLUMN last_name TYPE varchar(100) COLLATE "default";

            ALTER TABLE patients
                DROP COLUMN IF EXISTS created_by,
                DROP COLUMN IF EXISTS merged_into_patient_id,
                DROP COLUMN IF EXISTS deceased_on,
                DROP COLUMN IF EXISTS last_attention_at,
                DROP COLUMN IF EXISTS first_attention_at,
                DROP COLUMN IF EXISTS archive_status,
                DROP COLUMN IF EXISTS search_name,
                DROP COLUMN IF EXISTS address,
                DROP COLUMN IF EXISTS sex,
                DROP COLUMN IF EXISTS clinical_record_hash,
                DROP COLUMN IF EXISTS clinical_record_number,
                DROP COLUMN IF EXISTS document_hash,
                DROP COLUMN IF EXISTS document_number,
                DROP COLUMN IF EXISTS document_type;
            SQL);
    }
};
