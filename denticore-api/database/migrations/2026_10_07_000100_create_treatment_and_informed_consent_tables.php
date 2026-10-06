<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Esquema comercial y de consentimiento informado (TASK-054; SDD §2.8, §2.5, §2.6, §2.2; DD-06,
 * DD-07, DD-31, DI-19, DI-21, RN-26, RN-27, RN-29 a RN-31, RN-34 a RN-37, RN-76).
 *
 * - Catálogo de procedimientos, plan e ítems (BT), vínculo ítem–hallazgo y decisión de no tratar.
 * - `budgets` (BT): emitido, solo cambian las columnas de estado, decisión, reemplazo y
 *   vencimiento (`trg_budgets_immutable`, RN-34). `budget_lines` (BT): solo cambian en borrador.
 * - Consentimiento informado: plantillas (BT), versiones (BTi), pivote procedimiento–plantilla y
 *   consentimientos (BT, inmutables salvo su estado).
 * - `performed_procedures` (BTi).
 *
 * Los disparadores aceptan las excepciones de DI-21: `app.patient_merge` cambia solo `patient_id`
 * y `app.retention_delete` permite DELETE (SDD §2.2). Sin FK por ahora: `plan_items.ai_suggestion_id`
 * (MS-12) y las referencias por `odontogram_entry_uuid` (tabla particionada, SDD §2.1).
 * `procedure_price_history` queda en MS-15 y `treatment_plans.risk_alert_id` en TASK-078.
 */
return new class extends Migration
{
    private const TABLES = [
        'procedure_catalog', 'treatment_plans', 'plan_items', 'plan_item_findings', 'finding_no_treat_decisions',
        'informed_consent_templates', 'informed_consent_template_versions', 'procedure_informed_consent_template',
        'budgets', 'budget_lines', 'informed_consents', 'performed_procedures',
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- DI-21: BTi con patient_id; la fusión cambia solo patient_id y la retención elimina.
            CREATE OR REPLACE FUNCTION fn_forbid_update_delete_except_patient_merge() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                IF current_setting('app.retention_delete', true) = 'on' THEN RETURN OLD; END IF;
              ELSIF current_setting('app.patient_merge', true) = 'on'
                    AND (to_jsonb(NEW) - 'patient_id') = (to_jsonb(OLD) - 'patient_id') THEN
                RETURN NEW;
              END IF;
              RAISE EXCEPTION 'immutable_row: % on %', TG_OP, TG_TABLE_NAME USING ERRCODE = '55000';
            END $$;

            CREATE TABLE procedure_catalog (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                code varchar(30) NOT NULL,
                name varchar(150) NOT NULL,
                category varchar(60) NULL,
                price numeric(12,2) NOT NULL CONSTRAINT procedure_catalog_price_check CHECK (price >= 0 AND price <= 99999.99),
                requires_tooth boolean NOT NULL,
                requires_surface boolean NOT NULL,
                resulting_finding_id bigint NULL REFERENCES finding_catalog (id) ON DELETE RESTRICT,
                resulting_finding_state_id bigint NULL REFERENCES finding_states (id) ON DELETE RESTRICT,
                requires_informed_consent boolean NOT NULL DEFAULT false,
                is_active boolean NOT NULL DEFAULT false,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id),
                CONSTRAINT procedure_catalog_tenant_id_code_key UNIQUE (tenant_id, code),
                CONSTRAINT procedure_catalog_requires_check CHECK (NOT requires_surface OR requires_tooth)
            );

            CREATE TABLE treatment_plans (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                patient_id bigint NOT NULL,
                title varchar(150) NOT NULL,
                status varchar(15) NOT NULL DEFAULT 'borrador'
                    CHECK (status IN ('borrador', 'propuesto', 'aceptado', 'en_ejecucion', 'completado', 'cancelado')),
                origin varchar(10) NOT NULL DEFAULT 'manual' CHECK (origin IN ('manual', 'ia', 'urgencia', 'alerta')),
                created_by bigint NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                cancel_reason varchar(500) NULL,
                cancelled_at timestamptz NULL,
                completed_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id),
                FOREIGN KEY (tenant_id, patient_id) REFERENCES patients (tenant_id, id) ON DELETE RESTRICT
            );
            CREATE INDEX treatment_plans_patient_status_idx ON treatment_plans (tenant_id, patient_id, status);

            CREATE TABLE plan_items (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                treatment_plan_id bigint NOT NULL,
                position smallint NOT NULL,
                procedure_id bigint NOT NULL,
                tooth smallint NULL CONSTRAINT plan_items_tooth_check CHECK (tooth IS NULL OR fn_valid_tooth(tooth)),
                surfaces text[] NOT NULL DEFAULT '{}' CONSTRAINT plan_items_surfaces_check
                    CHECK ((tooth IS NULL AND surfaces = '{}') OR (tooth IS NOT NULL AND fn_valid_surfaces(tooth, surfaces))),
                quantity smallint NOT NULL DEFAULT 1 CONSTRAINT plan_items_quantity_check CHECK (quantity BETWEEN 1 AND 32),
                performed_quantity smallint NOT NULL DEFAULT 0
                    CONSTRAINT plan_items_performed_quantity_check CHECK (performed_quantity <= quantity),
                session_number smallint NULL,
                observations varchar(500) NULL,
                status varchar(10) NOT NULL DEFAULT 'propuesto'
                    CHECK (status IN ('propuesto', 'aceptado', 'realizado', 'descartado')),
                discard_reason varchar(500) NULL,
                origin varchar(10) NOT NULL DEFAULT 'manual' CHECK (origin IN ('manual', 'ia')),
                ai_suggestion_id bigint NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id),
                CONSTRAINT plan_items_treatment_plan_id_position_key UNIQUE (treatment_plan_id, position),
                CONSTRAINT plan_items_discard_reason_check CHECK (status <> 'descartado' OR discard_reason IS NOT NULL),
                FOREIGN KEY (tenant_id, treatment_plan_id) REFERENCES treatment_plans (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, procedure_id) REFERENCES procedure_catalog (tenant_id, id) ON DELETE RESTRICT
            );

            CREATE TABLE plan_item_findings (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                plan_item_id bigint NOT NULL,
                odontogram_entry_uuid uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id),
                UNIQUE (plan_item_id, odontogram_entry_uuid),
                FOREIGN KEY (tenant_id, plan_item_id) REFERENCES plan_items (tenant_id, id) ON DELETE RESTRICT
            );

            CREATE TABLE finding_no_treat_decisions (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                odontogram_entry_uuid uuid NOT NULL,
                patient_id bigint NOT NULL,
                reason varchar(500) NOT NULL CONSTRAINT finding_no_treat_decisions_reason_check CHECK (char_length(reason) >= 10),
                decided_by bigint NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (tenant_id, id),
                UNIQUE (tenant_id, odontogram_entry_uuid),
                FOREIGN KEY (tenant_id, patient_id) REFERENCES patients (tenant_id, id) ON DELETE RESTRICT
            );
            CREATE TRIGGER trg_forbid_update_delete BEFORE UPDATE OR DELETE ON finding_no_treat_decisions
                FOR EACH ROW EXECUTE FUNCTION fn_forbid_update_delete_except_patient_merge();

            CREATE TABLE informed_consent_templates (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                title varchar(150) NOT NULL,
                is_active boolean NOT NULL,
                current_version smallint NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id)
            );

            CREATE TABLE informed_consent_template_versions (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                informed_consent_template_id bigint NOT NULL,
                version smallint NOT NULL CHECK (version > 0),
                body text NOT NULL,
                body_sha256 char(64) NOT NULL,
                created_by bigint NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (tenant_id, id),
                UNIQUE (informed_consent_template_id, version),
                FOREIGN KEY (tenant_id, informed_consent_template_id)
                    REFERENCES informed_consent_templates (tenant_id, id) ON DELETE RESTRICT
            );
            CREATE TRIGGER trg_forbid_update_delete BEFORE UPDATE OR DELETE ON informed_consent_template_versions
                FOR EACH ROW EXECUTE FUNCTION fn_forbid_update_delete();

            CREATE TABLE procedure_informed_consent_template (
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                procedure_id bigint NOT NULL,
                informed_consent_template_id bigint NOT NULL,
                PRIMARY KEY (tenant_id, procedure_id, informed_consent_template_id),
                FOREIGN KEY (tenant_id, procedure_id) REFERENCES procedure_catalog (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, informed_consent_template_id)
                    REFERENCES informed_consent_templates (tenant_id, id) ON DELETE RESTRICT
            );

            CREATE TABLE budgets (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                patient_id bigint NOT NULL,
                treatment_plan_id bigint NOT NULL,
                number varchar(12) NULL,
                status varchar(12) NOT NULL DEFAULT 'borrador'
                    CHECK (status IN ('borrador', 'emitido', 'aceptado', 'rechazado', 'vencido', 'reemplazado')),
                corrects_budget_id bigint NULL,
                prices_include_igv boolean NOT NULL,
                igv_rate numeric(5,4) NOT NULL DEFAULT 0.1800,
                subtotal numeric(12,2) NOT NULL DEFAULT 0,
                discount_total numeric(12,2) NOT NULL DEFAULT 0,
                base_amount numeric(12,2) NOT NULL DEFAULT 0,
                igv_amount numeric(12,2) NOT NULL DEFAULT 0,
                total numeric(12,2) NOT NULL DEFAULT 0,
                validity_days smallint NULL,
                issued_at timestamptz NULL,
                expires_at timestamptz NULL,
                issued_by bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
                dentist_id bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
                terms_snapshot text NULL,
                pdf_document_id bigint NULL,
                decision_channel varchar(10) NULL CHECK (decision_channel IN ('portal', 'presencial', 'enlace')),
                decision_by_user_id bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
                decision_signer varchar(15) NULL CHECK (decision_signer IN ('titular', 'representante')),
                decision_signer_document_hash char(64) NULL,
                decided_at timestamptz NULL,
                decision_ip inet NULL,
                decision_user_agent varchar(300) NULL,
                rejection_reason varchar(25) NULL
                    CHECK (rejection_reason IN ('precio', 'segunda_opinion', 'momento_no_oportuno', 'otro')),
                rejection_detail varchar(200) NULL,
                signed_file_id bigint NULL,
                decision_evidence_hmac char(64) NULL,
                replaced_at timestamptz NULL,
                expired_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id),
                CONSTRAINT budgets_tenant_id_number_key UNIQUE (tenant_id, number),
                CONSTRAINT budgets_total_check CHECK (base_amount + igv_amount = total),
                CONSTRAINT budgets_issued_check
                    CHECK (status = 'borrador' OR (number IS NOT NULL AND issued_at IS NOT NULL AND expires_at IS NOT NULL)),
                CONSTRAINT budgets_decided_check CHECK (status NOT IN ('aceptado', 'rechazado') OR decided_at IS NOT NULL),
                FOREIGN KEY (tenant_id, patient_id) REFERENCES patients (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, treatment_plan_id) REFERENCES treatment_plans (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, corrects_budget_id) REFERENCES budgets (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, pdf_document_id) REFERENCES generated_documents (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, signed_file_id) REFERENCES stored_files (tenant_id, id) ON DELETE RESTRICT
            );
            CREATE UNIQUE INDEX budgets_accepted_unique ON budgets (tenant_id, treatment_plan_id) WHERE status = 'aceptado';
            CREATE INDEX budgets_status_expires_idx ON budgets (tenant_id, status, expires_at);
            CREATE INDEX budgets_patient_created_idx ON budgets (tenant_id, patient_id, created_at DESC);

            -- RN-34: emitido, solo cambian el estado, la decisión, el reemplazo y el vencimiento.
            CREATE OR REPLACE FUNCTION fn_budgets_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
              allowed text[] := ARRAY['status', 'decision_channel', 'decision_by_user_id', 'decision_signer',
                'decision_signer_document_hash', 'decided_at', 'decision_ip', 'decision_user_agent',
                'decision_evidence_hmac', 'rejection_reason', 'rejection_detail', 'signed_file_id',
                'pdf_document_id', 'replaced_at', 'expired_at', 'updated_at'];
            BEGIN
              IF TG_OP = 'DELETE' THEN
                IF OLD.status = 'borrador' OR current_setting('app.retention_delete', true) = 'on' THEN RETURN OLD; END IF;
                RAISE EXCEPTION 'immutable_row: DELETE on budgets' USING ERRCODE = '55000';
              END IF;
              IF OLD.status = 'borrador' THEN RETURN NEW; END IF;
              IF current_setting('app.patient_merge', true) = 'on' THEN
                allowed := array_append(allowed, 'patient_id');
              END IF;
              IF (to_jsonb(NEW) - allowed) IS DISTINCT FROM (to_jsonb(OLD) - allowed) THEN
                RAISE EXCEPTION 'immutable_row: UPDATE on budgets' USING ERRCODE = '55000';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_budgets_immutable BEFORE UPDATE OR DELETE ON budgets
                FOR EACH ROW EXECUTE FUNCTION fn_budgets_immutable();

            CREATE TABLE budget_lines (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                budget_id bigint NOT NULL,
                plan_item_id bigint NOT NULL,
                procedure_id bigint NOT NULL,
                description varchar(150) NOT NULL,
                tooth smallint NULL,
                surfaces text[] NOT NULL DEFAULT '{}',
                unit_price numeric(12,2) NOT NULL,
                quantity smallint NOT NULL CONSTRAINT budget_lines_quantity_check CHECK (quantity BETWEEN 1 AND 32),
                discount_pct numeric(5,2) NOT NULL DEFAULT 0
                    CONSTRAINT budget_lines_discount_pct_check CHECK (discount_pct BETWEEN 0 AND 100),
                discount_reason varchar(200) NULL,
                discount_approved_by bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
                subtotal numeric(12,2) NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id),
                CONSTRAINT budget_lines_budget_id_plan_item_id_key UNIQUE (budget_id, plan_item_id),
                CONSTRAINT budget_lines_discount_reason_check
                    CHECK (discount_pct = 0 OR (discount_reason IS NOT NULL AND char_length(discount_reason) >= 5)),
                FOREIGN KEY (tenant_id, budget_id) REFERENCES budgets (tenant_id, id) ON DELETE CASCADE,
                FOREIGN KEY (tenant_id, plan_item_id) REFERENCES plan_items (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, procedure_id) REFERENCES procedure_catalog (tenant_id, id) ON DELETE RESTRICT
            );

            -- RN-34: las líneas solo cambian mientras el presupuesto es borrador. Si el presupuesto ya
            -- no existe, el borrado viene en cascada de un borrador que su disparador autorizó.
            CREATE OR REPLACE FUNCTION fn_budget_lines_draft() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE budget_id_value bigint; budget_status text;
            BEGIN
              IF TG_OP = 'DELETE' AND current_setting('app.retention_delete', true) = 'on' THEN RETURN OLD; END IF;
              IF TG_OP = 'INSERT' THEN budget_id_value := NEW.budget_id; ELSE budget_id_value := OLD.budget_id; END IF;
              SELECT status INTO budget_status FROM budgets WHERE id = budget_id_value;
              IF budget_status IS NOT NULL AND budget_status <> 'borrador' THEN
                RAISE EXCEPTION 'immutable_row: % on budget_lines of a budget that is not a draft', TG_OP
                    USING ERRCODE = '55000';
              END IF;
              IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_budget_lines_draft BEFORE INSERT OR UPDATE OR DELETE ON budget_lines
                FOR EACH ROW EXECUTE FUNCTION fn_budget_lines_draft();

            CREATE TABLE informed_consents (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                patient_id bigint NOT NULL,
                plan_item_id bigint NOT NULL,
                template_version_id bigint NOT NULL,
                rendered_text text NOT NULL,
                text_sha256 char(64) NOT NULL,
                signer varchar(15) NOT NULL CHECK (signer IN ('titular', 'representante')),
                legal_representative_id bigint NULL,
                channel varchar(12) NOT NULL CHECK (channel IN ('dispositivo', 'papel')),
                scanned_file_id bigint NULL,
                informed_by bigint NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                registered_by bigint NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                signed_at timestamptz NOT NULL,
                ip_address inet NULL,
                evidence_hmac char(64) NOT NULL,
                status varchar(10) NOT NULL DEFAULT 'vigente' CHECK (status IN ('vigente', 'utilizado', 'revocado')),
                used_at timestamptz NULL,
                revoked_at timestamptz NULL,
                revocation_reason varchar(500) NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id),
                CONSTRAINT informed_consents_representative_check
                    CHECK ((signer = 'representante') = (legal_representative_id IS NOT NULL)),
                CONSTRAINT informed_consents_scanned_file_check CHECK (channel <> 'papel' OR scanned_file_id IS NOT NULL),
                CONSTRAINT informed_consents_revocation_check CHECK (status <> 'revocado' OR revocation_reason IS NOT NULL),
                FOREIGN KEY (tenant_id, patient_id) REFERENCES patients (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, plan_item_id) REFERENCES plan_items (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, template_version_id)
                    REFERENCES informed_consent_template_versions (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, legal_representative_id) REFERENCES legal_representatives (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, scanned_file_id) REFERENCES stored_files (tenant_id, id) ON DELETE RESTRICT
            );
            CREATE INDEX informed_consents_item_status_idx ON informed_consents (tenant_id, plan_item_id, status);

            -- DD-31: firmado, solo cambian su estado y la revocación (DI-21: fusión y retención).
            CREATE OR REPLACE FUNCTION fn_informed_consents_allowed_columns() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE allowed text[] := ARRAY['status', 'used_at', 'revoked_at', 'revocation_reason', 'updated_at'];
            BEGIN
              IF TG_OP = 'DELETE' THEN
                IF current_setting('app.retention_delete', true) = 'on' THEN RETURN OLD; END IF;
                RAISE EXCEPTION 'immutable_row: DELETE on informed_consents' USING ERRCODE = '55000';
              END IF;
              IF current_setting('app.patient_merge', true) = 'on' THEN
                allowed := array_append(allowed, 'patient_id');
              END IF;
              IF (to_jsonb(NEW) - allowed) IS DISTINCT FROM (to_jsonb(OLD) - allowed) THEN
                RAISE EXCEPTION 'immutable_row: UPDATE on informed_consents' USING ERRCODE = '55000';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_informed_consents_allowed_columns BEFORE UPDATE OR DELETE ON informed_consents
                FOR EACH ROW EXECUTE FUNCTION fn_informed_consents_allowed_columns();

            CREATE TABLE performed_procedures (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                patient_id bigint NOT NULL,
                plan_item_id bigint NOT NULL,
                attention_id bigint NOT NULL,
                dentist_id bigint NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                quantity smallint NOT NULL CONSTRAINT performed_procedures_quantity_check CHECK (quantity >= 1),
                performed_at timestamptz NOT NULL,
                observations varchar(1000) NULL,
                informed_consent_id bigint NULL,
                odontogram_entry_uuid uuid NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (tenant_id, id),
                FOREIGN KEY (tenant_id, patient_id) REFERENCES patients (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, plan_item_id) REFERENCES plan_items (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, attention_id) REFERENCES attentions (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, informed_consent_id) REFERENCES informed_consents (tenant_id, id) ON DELETE RESTRICT
            );
            CREATE TRIGGER trg_forbid_update_delete BEFORE UPDATE OR DELETE ON performed_procedures
                FOR EACH ROW EXECUTE FUNCTION fn_forbid_update_delete_except_patient_merge();
            SQL);

        foreach (self::TABLES as $table) {
            RowLevelSecurity::enable($table);
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS performed_procedures;
            DROP TABLE IF EXISTS informed_consents;
            DROP FUNCTION IF EXISTS fn_informed_consents_allowed_columns();
            DROP TABLE IF EXISTS budget_lines;
            DROP FUNCTION IF EXISTS fn_budget_lines_draft();
            DROP TABLE IF EXISTS budgets;
            DROP FUNCTION IF EXISTS fn_budgets_immutable();
            DROP TABLE IF EXISTS procedure_informed_consent_template;
            DROP TABLE IF EXISTS informed_consent_template_versions;
            DROP TABLE IF EXISTS informed_consent_templates;
            DROP TABLE IF EXISTS finding_no_treat_decisions;
            DROP TABLE IF EXISTS plan_item_findings;
            DROP TABLE IF EXISTS plan_items;
            DROP TABLE IF EXISTS treatment_plans;
            DROP TABLE IF EXISTS procedure_catalog;
            DROP FUNCTION IF EXISTS fn_forbid_update_delete_except_patient_merge();
            SQL);
    }
};
