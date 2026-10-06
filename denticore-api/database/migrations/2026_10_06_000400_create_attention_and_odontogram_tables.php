<?php

use App\Support\Audit\AuditPartitions;
use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Atención, nota clínica y odontograma (TASK-045; SDD §2.6; DD-05, DD-30, DD-46, DI-11, DI-17,
 * DI-19, DI-21, RN-20, RN-22, RN-78).
 *
 * - `attentions` (BT): una sola atención `abierta` por odontólogo y paciente (RF-082). La firma y
 *   el `evidence_hmac` del cierre los exige AttentionService (TASK-047). `appointment_id` se
 *   agrega en TASK-064, cuando exista `appointments`.
 * - `clinical_notes` (BT, 1:1): no se modifica firmada ni con la atención fuera de `abierta`.
 * - `attention_addenda` (BTi) y `attention_diagnoses` (BT): con la atención fuera de `abierta`,
 *   los diagnósticos no cambian y los nuevos solo llegan desde una adenda (RN-78).
 * - `initial_odontograms` (BT): una vez `cerrado`, no admite cambios (RN-20).
 * - `odontogram_entries` (BTi): particionada por año de `recorded_at`, con cadena de hashes por
 *   `chain_patient_id` (excluye `patient_id`, que solo cambia en una fusión, DI-21) y RLS en el
 *   padre y en cada partición. `ai_suggestion_id` y `performed_procedure_id` quedan sin FK hasta
 *   que existan sus tablas; `corrects_entry_id` no admite FK hacia una tabla particionada y lo
 *   valida OdontogramCorrectionService (TASK-049).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE attentions (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                patient_id bigint NOT NULL,
                dentist_id bigint NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                status varchar(20) NOT NULL DEFAULT 'abierta'
                    CHECK (status IN ('abierta', 'cerrada', 'cerrada_incompleta')),
                is_first_attention boolean NOT NULL,
                opened_at timestamptz NOT NULL,
                clinical_started_at timestamptz NULL,
                closed_at timestamptz NULL,
                closed_by_system boolean NOT NULL DEFAULT false,
                signed_by bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
                signer_cop varchar(10) NULL,
                signed_at timestamptz NULL,
                evidence_hmac char(64) NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id),
                FOREIGN KEY (tenant_id, patient_id) REFERENCES patients (tenant_id, id) ON DELETE RESTRICT
            );
            CREATE UNIQUE INDEX attentions_open_unique ON attentions (tenant_id, patient_id, dentist_id) WHERE status = 'abierta';
            CREATE INDEX attentions_dentist_status_idx ON attentions (tenant_id, dentist_id, status);
            CREATE INDEX attentions_patient_opened_idx ON attentions (tenant_id, patient_id, opened_at DESC);

            CREATE TABLE clinical_notes (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                attention_id bigint NOT NULL UNIQUE,
                chief_complaint text NULL CHECK (char_length(chief_complaint) <= 2000),
                current_illness text NULL CHECK (char_length(current_illness) <= 5000),
                extraoral_exam text NULL CHECK (char_length(extraoral_exam) <= 5000),
                intraoral_exam text NULL CHECK (char_length(intraoral_exam) <= 5000),
                indications text NULL CHECK (char_length(indications) <= 5000),
                status varchar(10) NOT NULL DEFAULT 'borrador' CHECK (status IN ('borrador', 'firmada')),
                autosaved_at timestamptz NULL,
                signed_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id),
                FOREIGN KEY (tenant_id, attention_id) REFERENCES attentions (tenant_id, id) ON DELETE RESTRICT
            );

            -- RN-78: la nota no cambia firmada ni con la atención fuera de `abierta`.
            CREATE OR REPLACE FUNCTION fn_clinical_notes_locked() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF OLD.status = 'firmada' THEN
                RAISE EXCEPTION 'immutable_row: signed clinical_notes' USING ERRCODE = '55000';
              END IF;
              IF (SELECT status FROM attentions WHERE id = OLD.attention_id) IS DISTINCT FROM 'abierta' THEN
                RAISE EXCEPTION 'immutable_row: clinical_notes of an attention that is not open' USING ERRCODE = '55000';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_clinical_notes_locked BEFORE UPDATE ON clinical_notes
                FOR EACH ROW EXECUTE FUNCTION fn_clinical_notes_locked();

            CREATE TABLE attention_addenda (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                attention_id bigint NOT NULL,
                text varchar(2000) NOT NULL,
                chief_complaint text NULL,
                author_id bigint NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                author_cop varchar(10) NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (tenant_id, id),
                FOREIGN KEY (tenant_id, attention_id) REFERENCES attentions (tenant_id, id) ON DELETE RESTRICT
            );
            CREATE TRIGGER trg_forbid_update_delete BEFORE UPDATE OR DELETE ON attention_addenda
                FOR EACH ROW EXECUTE FUNCTION fn_forbid_update_delete();

            CREATE TABLE attention_diagnoses (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                attention_id bigint NOT NULL,
                cie10_code varchar(7) NOT NULL REFERENCES cie10_codes (code) ON DELETE RESTRICT,
                type varchar(10) NOT NULL CHECK (type IN ('presuntivo', 'definitivo')),
                origin varchar(10) NOT NULL CHECK (origin IN ('nota', 'adenda')),
                addendum_id bigint NULL,
                created_by bigint NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id),
                FOREIGN KEY (tenant_id, attention_id) REFERENCES attentions (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, addendum_id) REFERENCES attention_addenda (tenant_id, id) ON DELETE RESTRICT
            );
            CREATE INDEX attention_diagnoses_attention_idx ON attention_diagnoses (tenant_id, attention_id);

            -- RN-78: con la atención fuera de `abierta`, los diagnósticos no cambian (salvo la
            -- eliminación por retención, DI-21) y los nuevos solo llegan desde una adenda.
            CREATE OR REPLACE FUNCTION fn_attention_diagnoses_locked() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' AND current_setting('app.retention_delete', true) = 'on' THEN RETURN OLD; END IF;
              IF (SELECT status FROM attentions WHERE id = OLD.attention_id) IS DISTINCT FROM 'abierta' THEN
                RAISE EXCEPTION 'immutable_row: % on attention_diagnoses of an attention that is not open', TG_OP
                    USING ERRCODE = '55000';
              END IF;
              IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_attention_diagnoses_locked BEFORE UPDATE OR DELETE ON attention_diagnoses
                FOR EACH ROW EXECUTE FUNCTION fn_attention_diagnoses_locked();

            CREATE OR REPLACE FUNCTION fn_attention_diagnoses_origin() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.origin <> 'adenda'
                 AND (SELECT status FROM attentions WHERE id = NEW.attention_id) IS DISTINCT FROM 'abierta' THEN
                RAISE EXCEPTION 'attention_not_open: diagnoses of a closed attention come from an addendum'
                    USING ERRCODE = '55000';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_attention_diagnoses_origin BEFORE INSERT ON attention_diagnoses
                FOR EACH ROW EXECUTE FUNCTION fn_attention_diagnoses_origin();

            CREATE TABLE initial_odontograms (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                patient_id bigint NOT NULL,
                attention_id bigint NOT NULL,
                status varchar(10) NOT NULL DEFAULT 'abierto' CHECK (status IN ('abierto', 'cerrado')),
                closed_at timestamptz NULL,
                closed_by varchar(20) NULL CHECK (closed_by IN ('cierre_atencion', 'cierre_automatico')),
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id),
                UNIQUE (tenant_id, patient_id),
                FOREIGN KEY (tenant_id, patient_id) REFERENCES patients (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, attention_id) REFERENCES attentions (tenant_id, id) ON DELETE RESTRICT
            );

            -- RN-20: el odontograma inicial cerrado no cambia.
            CREATE OR REPLACE FUNCTION fn_initial_odontograms_locked() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF OLD.status = 'cerrado' THEN
                RAISE EXCEPTION 'immutable_row: closed initial_odontograms' USING ERRCODE = '55000';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_initial_odontograms_locked BEFORE UPDATE ON initial_odontograms
                FOR EACH ROW EXECUTE FUNCTION fn_initial_odontograms_locked();

            CREATE TABLE odontogram_entries (
                id bigint GENERATED ALWAYS AS IDENTITY,
                uuid uuid NOT NULL DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                patient_id bigint NOT NULL,
                chain_patient_id bigint NOT NULL,
                attention_id bigint NOT NULL,
                initial_odontogram_id bigint NULL,
                entry_type varchar(10) NOT NULL CHECK (entry_type IN ('inicial', 'evolucion', 'correccion')),
                tooth smallint NOT NULL CONSTRAINT odontogram_entries_tooth_check CHECK (fn_valid_tooth(tooth)),
                tooth_end smallint NULL CONSTRAINT odontogram_entries_tooth_end_check
                    CHECK (tooth_end IS NULL OR (fn_valid_tooth(tooth_end) AND fn_same_arch(tooth, tooth_end) AND tooth_end <> tooth)),
                surfaces text[] NOT NULL DEFAULT '{}'
                    CONSTRAINT odontogram_entries_surfaces_check CHECK (fn_valid_surfaces(tooth, surfaces)),
                finding_id bigint NULL REFERENCES finding_catalog (id) ON DELETE RESTRICT,
                finding_state_id bigint NULL REFERENCES finding_states (id) ON DELETE RESTRICT,
                color varchar(5) NULL CONSTRAINT odontogram_entries_color_check CHECK (color IN ('azul', 'rojo')),
                origin varchar(15) NOT NULL CHECK (origin IN ('manual', 'ia', 'procedimiento')),
                ai_suggestion_id bigint NULL,
                performed_procedure_id bigint NULL,
                corrects_entry_id bigint NULL,
                correction_kind varchar(10) NULL
                    CONSTRAINT odontogram_entries_correction_kind_domain_check CHECK (correction_kind IN ('anulacion', 'reemplazo')),
                correction_reason varchar(500) NULL,
                note varchar(500) NULL,
                author_id bigint NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                author_cop varchar(10) NOT NULL,
                recorded_at timestamptz NOT NULL DEFAULT now(),
                prev_hash char(64) NOT NULL,
                hash char(64) NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (id, recorded_at),
                UNIQUE (uuid, recorded_at),
                CONSTRAINT odontogram_entries_initial_check CHECK (entry_type <> 'inicial' OR initial_odontogram_id IS NOT NULL),
                CONSTRAINT odontogram_entries_annulment_check CHECK (
                    (finding_id IS NULL) = (correction_kind IS NOT DISTINCT FROM 'anulacion')
                    AND (finding_state_id IS NULL) = (correction_kind IS NOT DISTINCT FROM 'anulacion')
                    AND (color IS NULL) = (correction_kind IS NOT DISTINCT FROM 'anulacion')),
                CONSTRAINT odontogram_entries_ai_suggestion_check CHECK (origin <> 'ia' OR ai_suggestion_id IS NOT NULL),
                CONSTRAINT odontogram_entries_procedure_check CHECK (origin <> 'procedimiento' OR performed_procedure_id IS NOT NULL),
                CONSTRAINT odontogram_entries_correction_reason_check CHECK (entry_type <> 'correccion'
                    OR (corrects_entry_id IS NOT NULL AND correction_reason IS NOT NULL AND char_length(correction_reason) >= 10)),
                CONSTRAINT odontogram_entries_correction_check CHECK ((entry_type = 'correccion') = (corrects_entry_id IS NOT NULL)),
                CONSTRAINT odontogram_entries_correction_kind_check CHECK ((correction_kind IS NULL) = (entry_type <> 'correccion')),
                FOREIGN KEY (tenant_id, patient_id) REFERENCES patients (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, attention_id) REFERENCES attentions (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, initial_odontogram_id) REFERENCES initial_odontograms (tenant_id, id) ON DELETE RESTRICT
            ) PARTITION BY RANGE (recorded_at);

            CREATE INDEX odontogram_entries_patient_recorded_idx ON odontogram_entries (tenant_id, patient_id, recorded_at);
            CREATE INDEX odontogram_entries_patient_tooth_idx ON odontogram_entries (tenant_id, patient_id, tooth, recorded_at);
            CREATE INDEX odontogram_entries_corrects_idx ON odontogram_entries (tenant_id, corrects_entry_id)
                WHERE corrects_entry_id IS NOT NULL;

            -- chain_patient_id = patient_id al insertar (DD-46) y color del estado elegido (RN-17).
            CREATE OR REPLACE FUNCTION fn_odontogram_entry_checks() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.chain_patient_id IS DISTINCT FROM NEW.patient_id THEN
                RAISE EXCEPTION 'odontogram_entries_chain_patient_check: chain_patient_id must equal patient_id'
                    USING ERRCODE = '23514';
              END IF;
              IF NEW.finding_state_id IS NOT NULL AND NOT EXISTS (
                   SELECT 1 FROM finding_states s
                    WHERE s.id = NEW.finding_state_id AND s.finding_id = NEW.finding_id AND s.color = NEW.color) THEN
                RAISE EXCEPTION 'odontogram_entries_state_color_check: color must be the one of the finding state'
                    USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_entry_checks BEFORE INSERT ON odontogram_entries
                FOR EACH ROW EXECUTE FUNCTION fn_odontogram_entry_checks();

            -- RN-22 con las excepciones de DI-21: la fusión cambia solo patient_id y la retención elimina.
            CREATE OR REPLACE FUNCTION fn_odontogram_entries_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                IF current_setting('app.retention_delete', true) = 'on' THEN RETURN OLD; END IF;
              ELSIF current_setting('app.patient_merge', true) = 'on'
                    AND (to_jsonb(NEW) - 'patient_id') = (to_jsonb(OLD) - 'patient_id') THEN
                RETURN NEW;
              END IF;
              RAISE EXCEPTION 'immutable_row: % on odontogram_entries', TG_OP USING ERRCODE = '55000';
            END $$;
            CREATE TRIGGER trg_forbid_update_delete BEFORE UPDATE OR DELETE ON odontogram_entries
                FOR EACH ROW EXECUTE FUNCTION fn_odontogram_entries_immutable();

            CREATE TRIGGER trg_hash_chain BEFORE INSERT ON odontogram_entries
                FOR EACH ROW EXECUTE FUNCTION fn_hash_chain('odontogram_entries', 'chain_patient_id', 'patient_id');
            SQL);

        foreach (['attentions', 'clinical_notes', 'attention_addenda', 'attention_diagnoses', 'initial_odontograms', 'odontogram_entries'] as $table) {
            RowLevelSecurity::enable($table);
        }

        // Las particiones copian la RLS del padre (DI-17, S-10).
        AuditPartitions::ensure(DB::connection(), 'odontogram_entries', (int) now()->format('Y'));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS odontogram_entries CASCADE;
            DROP FUNCTION IF EXISTS fn_odontogram_entries_immutable();
            DROP FUNCTION IF EXISTS fn_odontogram_entry_checks();
            DROP TABLE IF EXISTS initial_odontograms;
            DROP FUNCTION IF EXISTS fn_initial_odontograms_locked();
            DROP TABLE IF EXISTS attention_diagnoses;
            DROP FUNCTION IF EXISTS fn_attention_diagnoses_origin();
            DROP FUNCTION IF EXISTS fn_attention_diagnoses_locked();
            DROP TABLE IF EXISTS attention_addenda;
            DROP TABLE IF EXISTS clinical_notes;
            DROP FUNCTION IF EXISTS fn_clinical_notes_locked();
            DROP TABLE IF EXISTS attentions;
            SQL);
    }
};
