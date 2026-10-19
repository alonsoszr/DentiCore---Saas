<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Solicitudes ARCO (TASK-103; SDD §2.12, §5.11; CUS-63, CUS-64; RF-182 a RF-185, RN-69, RN-81,
 * DD-14, DD-26).
 *
 * - `arco_requests` (BT): tipo, solicitante (titular o representante vigente), verificación de
 *   identidad, canal, descripción, estado, plazo de 10 días hábiles (`due_date`) y resolución. Los
 *   CHECK exigen la respuesta (≥ 20) al resolver y el fundamento (≥ 20) al denegar (SRS §11.15).
 *   `correction_entry_uuid` apunta a `odontogram_entries`, particionada: por uuid y sin FK (DI-17).
 * - `arco_request_files` (BT): adjuntos de la solicitud (pivote con `stored_files`).
 * - Expansión de `patient_identity_history` y `consent_purpose_revocations` con `arco_request_id`
 *   (Plan §1.5): la rectificación y la oposición quedan vinculadas a su solicitud.
 */
return new class extends Migration
{
    private const TABLES = ['arco_requests', 'arco_request_files'];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE arco_requests (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                patient_id bigint NOT NULL,
                type varchar(15) NOT NULL
                    CHECK (type IN ('acceso', 'rectificacion', 'cancelacion', 'oposicion', 'portabilidad')),
                requested_by varchar(15) NOT NULL CHECK (requested_by IN ('titular', 'representante')),
                legal_representative_id bigint NULL,
                identity_verification varchar(25) NOT NULL
                    CHECK (identity_verification IN ('sesion_portal', 'documento_presencial')),
                channel varchar(10) NOT NULL CHECK (channel IN ('portal', 'presencial')),
                description varchar(2000) NOT NULL,
                status varchar(10) NOT NULL DEFAULT 'recibida'
                    CHECK (status IN ('recibida', 'en_tramite', 'atendida', 'denegada')),
                received_at timestamptz NOT NULL,
                due_date date NOT NULL,
                resolved_at timestamptz NULL,
                within_deadline boolean NULL,
                response varchar(5000) NULL,
                denial_reason varchar(2000) NULL,
                handled_by bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
                assigned_dentist_id bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
                correction_entry_uuid uuid NULL,
                response_document_id bigint NULL,
                reminder_sent_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id),
                FOREIGN KEY (tenant_id, patient_id) REFERENCES patients (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, legal_representative_id) REFERENCES legal_representatives (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, response_document_id) REFERENCES generated_documents (tenant_id, id) ON DELETE RESTRICT,
                CONSTRAINT arco_requests_representative_check
                    CHECK ((requested_by = 'representante') = (legal_representative_id IS NOT NULL)),
                CONSTRAINT arco_requests_verification_check
                    CHECK ((channel = 'portal') = (identity_verification = 'sesion_portal')),
                CONSTRAINT arco_requests_response_check
                    CHECK (status NOT IN ('atendida', 'denegada') OR (response IS NOT NULL AND char_length(response) >= 20)),
                CONSTRAINT arco_requests_denial_check
                    CHECK (status <> 'denegada' OR (denial_reason IS NOT NULL AND char_length(denial_reason) >= 20)),
                CONSTRAINT arco_requests_resolution_check
                    CHECK ((status IN ('atendida', 'denegada')) = (resolved_at IS NOT NULL))
            );
            CREATE INDEX arco_requests_inbox_idx ON arco_requests (tenant_id, status, due_date);
            CREATE INDEX arco_requests_patient_idx ON arco_requests (tenant_id, patient_id, received_at DESC);

            CREATE TABLE arco_request_files (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                arco_request_id bigint NOT NULL,
                stored_file_id bigint NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (arco_request_id, stored_file_id),
                FOREIGN KEY (tenant_id, arco_request_id) REFERENCES arco_requests (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, stored_file_id) REFERENCES stored_files (tenant_id, id) ON DELETE RESTRICT
            );

            ALTER TABLE patient_identity_history ADD COLUMN arco_request_id bigint NULL;
            ALTER TABLE patient_identity_history ADD CONSTRAINT patient_identity_history_arco_request_fk
              FOREIGN KEY (tenant_id, arco_request_id) REFERENCES arco_requests (tenant_id, id) ON DELETE RESTRICT;
            ALTER TABLE consent_purpose_revocations ADD COLUMN arco_request_id bigint NULL;
            ALTER TABLE consent_purpose_revocations ADD CONSTRAINT consent_purpose_revocations_arco_request_fk
              FOREIGN KEY (tenant_id, arco_request_id) REFERENCES arco_requests (tenant_id, id) ON DELETE RESTRICT;
            SQL);

        foreach (self::TABLES as $table) {
            RowLevelSecurity::enable($table);
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE consent_purpose_revocations DROP COLUMN IF EXISTS arco_request_id;
            ALTER TABLE patient_identity_history DROP COLUMN IF EXISTS arco_request_id;
            DROP TABLE IF EXISTS arco_request_files;
            DROP TABLE IF EXISTS arco_requests;
            SQL);
    }
};
