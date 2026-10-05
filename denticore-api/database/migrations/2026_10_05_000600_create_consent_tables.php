<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Consentimiento de datos (TASK-035; SDD §2.5; DD-14, DD-28, RN-10 a RN-15, DI-19, DI-21).
 *
 * - `consent_templates`: plantilla de plataforma versionada e inmutable, con la semilla v1 y
 *   `platform_settings.consent.current_version = 1`. El texto v1 es un borrador pendiente de
 *   revisión legal: el SRS y el SDD solo fijan sus marcadores y las finalidades (a)–(e) de
 *   RN-11. Cada finalidad va en su propia línea para que `ConsentRenderer` (TASK-036) omita
 *   (c) y (d) cuando el plan no las incluye. Corregir el texto es publicar la versión 2 (RN-15).
 * - `consents` (BT): inmutable salvo `status`, `superseded_at`, `revoked_at` y `updated_at`;
 *   `patient_id` solo con `app.patient_merge` (DI-21) y `DELETE` solo con
 *   `app.retention_delete`. `certificate_document_id` admite una única escritura de NULL a un
 *   valor, porque la constancia se genera después del alta (desviación aprobada de §2.5).
 * - `consent_purpose_revocations` (BTi). `arco_request_id` se agrega en TASK-103.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE consent_templates (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                version smallint NOT NULL UNIQUE CHECK (version > 0),
                body text NOT NULL,
                body_sha256 char(64) NOT NULL,
                published_at timestamptz NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            );
            CREATE TRIGGER trg_forbid_update_delete BEFORE UPDATE OR DELETE ON consent_templates
                FOR EACH ROW EXECUTE FUNCTION fn_forbid_update_delete();

            CREATE TABLE consents (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                patient_id bigint NOT NULL,
                consent_template_version smallint NOT NULL
                    CONSTRAINT consents_consent_template_version_fkey
                    REFERENCES consent_templates (version) ON DELETE RESTRICT,
                purpose_care boolean NOT NULL CONSTRAINT consents_purpose_care_check CHECK (purpose_care),
                purpose_notifications boolean NOT NULL DEFAULT false,
                purpose_ai boolean NOT NULL DEFAULT false,
                purpose_risk boolean NOT NULL DEFAULT false,
                purpose_surveys boolean NOT NULL DEFAULT false,
                granted_by varchar(15) NOT NULL CHECK (granted_by IN ('titular', 'representante')),
                legal_representative_id bigint NULL,
                channel varchar(10) NOT NULL CHECK (channel IN ('presencial', 'portal', 'papel')),
                rendered_text text NOT NULL,
                text_sha256 char(64) NOT NULL,
                scanned_file_id bigint NULL,
                granted_at timestamptz NOT NULL,
                ip_address inet NULL,
                assisted_by bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
                evidence_hmac char(64) NOT NULL,
                status varchar(10) NOT NULL DEFAULT 'vigente' CHECK (status IN ('vigente', 'revocado', 'sustituido')),
                superseded_at timestamptz NULL,
                revoked_at timestamptz NULL,
                certificate_document_id bigint NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id),
                CONSTRAINT consents_representative_check
                    CHECK ((granted_by = 'representante') = (legal_representative_id IS NOT NULL)),
                CONSTRAINT consents_scanned_file_check CHECK (channel <> 'papel' OR scanned_file_id IS NOT NULL),
                CONSTRAINT consents_tenant_id_patient_id_fkey
                    FOREIGN KEY (tenant_id, patient_id) REFERENCES patients (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, legal_representative_id) REFERENCES legal_representatives (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, scanned_file_id) REFERENCES stored_files (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, certificate_document_id) REFERENCES generated_documents (tenant_id, id) ON DELETE RESTRICT
            );
            CREATE UNIQUE INDEX consents_current_unique ON consents (tenant_id, patient_id) WHERE status = 'vigente';
            CREATE INDEX consents_template_version_idx ON consents (tenant_id, consent_template_version);

            CREATE OR REPLACE FUNCTION fn_consents_allowed_columns() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                IF current_setting('app.retention_delete', true) = 'on' THEN RETURN OLD; END IF;  -- DI-21
                RAISE EXCEPTION 'immutable_row: DELETE on consents' USING ERRCODE = '55000';
              END IF;
              IF NEW.patient_id IS DISTINCT FROM OLD.patient_id
                 AND current_setting('app.patient_merge', true) IS DISTINCT FROM 'on' THEN          -- DI-21
                RAISE EXCEPTION 'immutable_row: patient_id on consents' USING ERRCODE = '55000';
              END IF;
              IF OLD.certificate_document_id IS NOT NULL
                 AND NEW.certificate_document_id IS DISTINCT FROM OLD.certificate_document_id THEN
                RAISE EXCEPTION 'immutable_row: certificate_document_id on consents' USING ERRCODE = '55000';
              END IF;
              IF (to_jsonb(NEW) - 'status' - 'superseded_at' - 'revoked_at' - 'updated_at'
                                - 'certificate_document_id' - 'patient_id')
                 IS DISTINCT FROM
                 (to_jsonb(OLD) - 'status' - 'superseded_at' - 'revoked_at' - 'updated_at'
                                - 'certificate_document_id' - 'patient_id') THEN
                RAISE EXCEPTION 'immutable_row: UPDATE on consents' USING ERRCODE = '55000';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_consents_allowed_columns BEFORE UPDATE OR DELETE ON consents
                FOR EACH ROW EXECUTE FUNCTION fn_consents_allowed_columns();

            CREATE TABLE consent_purpose_revocations (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                consent_id bigint NOT NULL,
                purpose varchar(15) NOT NULL
                    CHECK (purpose IN ('atencion', 'notificaciones', 'ia', 'prediccion', 'encuestas')),
                channel varchar(10) NOT NULL CHECK (channel IN ('presencial', 'portal', 'arco')),
                revoked_by_user_id bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
                reason varchar(500) NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (tenant_id, id),
                UNIQUE (consent_id, purpose),
                FOREIGN KEY (tenant_id, consent_id) REFERENCES consents (tenant_id, id) ON DELETE RESTRICT
            );
            CREATE TRIGGER trg_forbid_update_delete BEFORE UPDATE OR DELETE ON consent_purpose_revocations
                FOR EACH ROW EXECUTE FUNCTION fn_forbid_update_delete();
            SQL);

        DB::insert(
            "INSERT INTO consent_templates (version, body, body_sha256, published_at)
             VALUES (1, ?, encode(sha256(convert_to(?, 'UTF8')), 'hex'), now())",
            [$this->templateV1(), $this->templateV1()],
        );
        DB::insert("INSERT INTO platform_settings (key, value) VALUES ('consent.current_version', '1')");

        RowLevelSecurity::enable('consents');
        RowLevelSecurity::enable('consent_purpose_revocations');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('consent_purpose_revocations');
        RowLevelSecurity::disable('consents');
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS consent_purpose_revocations;
            DROP TABLE IF EXISTS consents;
            DROP FUNCTION IF EXISTS fn_consents_allowed_columns();
            DROP TABLE IF EXISTS consent_templates;
            DELETE FROM platform_settings WHERE key = 'consent.current_version';
            SQL);
    }

    /**
     * Borrador v1 (DD-28, RN-11, DD-14, DD-42, RF-047), pendiente de revisión legal.
     */
    private function templateV1(): string
    {
        return <<<'TEXT'
            CONSENTIMIENTO PARA EL TRATAMIENTO DE DATOS PERSONALES

            Yo, {{titular.nombre}}, autorizo a {{clinica.razon_social}}, con RUC {{clinica.ruc}} y domicilio en {{clinica.direccion}}, a tratar mis datos personales, incluidos mis datos de salud, conforme a la Ley N° 29733, Ley de Protección de Datos Personales, y a su Reglamento aprobado por el Decreto Supremo N° 016-2024-JUS.

            La clínica es la titular del banco de datos de sus pacientes. DentiCore trata los datos por cuenta de la clínica como encargado del tratamiento.

            Finalidades:
            (a) Atención odontológica: registro y uso de mi historia clínica para mi atención. Esta finalidad es obligatoria.
            (b) Notificaciones: envío de recordatorios y avisos por correo electrónico.
            (c) Asistencia de IA generativa: uso de mis datos clínicos por herramientas de inteligencia artificial que asisten al odontólogo.
            (d) Predicción de riesgo: uso de mis datos para estimar mi riesgo de caries mediante un modelo predictivo.
            (e) Encuestas: envío de encuestas de satisfacción sobre la atención recibida.

            Las finalidades (b) a (e) son opcionales. Puedo otorgarlas o revocarlas en cualquier momento, cada una de forma independiente.

            Transferencias: {{transferencias}}

            Puedo ejercer mis derechos de acceso, rectificación, cancelación y oposición (ARCO), y revocar este consentimiento, ante el Oficial de Datos Personales de la clínica: {{oficial.contacto}}.
            TEXT;
    }
};
