<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Esquema de riesgo de caries (TASK-078; SDD §2.11, §2.12; DD-12, RN-59 a RN-61, RN-64, RN-65,
 * RN-84, RF-164).
 *
 * - `model_versions` (BU, plataforma): una sola versión `activa` (índice único parcial) y CHECK de
 *   activación con AUC ≥ 0,75 y Brier informado (RN-84).
 * - `risk_variable_sets` (BT): secciones jsonb de §2.14.2; una vez usado en una predicción
 *   (`locked_at`) no admite cambios (RF-164, `trg_risk_variable_sets_locked`).
 * - `risk_predictions` (BTi salvo `status`): nivel coherente con los umbrales aplicados (RN-59),
 *   una sola `vigente` por paciente (RN-60) y UNIQUE(id, level), destino de la FK de las alertas.
 * - `risk_alerts` (BT): la FK compuesta (risk_prediction_id, prediction_level = 'alto') impide una
 *   alerta de una predicción que no es de nivel alto (RN-61); acción obligatoria al reconocer (RN-65).
 * - `external_call_logs` (B, `tenant_id` nulable; M13 adelantado por RN-64, Plan §8.4).
 * - Expansión de `appointments` y `treatment_plans` con `risk_alert_id` (Plan §2.3).
 *
 * Los disparadores aceptan las excepciones de DI-21: `app.patient_merge` cambia solo `patient_id`
 * y `app.retention_delete` permite DELETE (SDD §2.2).
 */
return new class extends Migration
{
    private const TABLES = ['risk_variable_sets', 'risk_predictions', 'risk_alerts'];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE model_versions (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                version varchar(30) NOT NULL UNIQUE
                    CONSTRAINT model_versions_version_semver_check CHECK (version ~ '^[0-9]+\.[0-9]+\.[0-9]+$'),
                trained_at timestamptz NOT NULL,
                dataset_description text NOT NULL,
                dataset_hash char(64) NOT NULL,
                code_commit varchar(40) NOT NULL,
                seed integer NOT NULL,
                hyperparameters jsonb NOT NULL,
                auc_validation numeric(5,4) NOT NULL,
                brier_score numeric(5,4) NULL,
                ece numeric(5,4) NULL,
                subgroup_metrics jsonb NULL,
                threshold_medium numeric(4,3) NOT NULL DEFAULT 0.300,
                threshold_high numeric(4,3) NOT NULL DEFAULT 0.600,
                model_card_file_id bigint NULL REFERENCES stored_files (id) ON DELETE RESTRICT,
                status varchar(10) NOT NULL DEFAULT 'registrada' CHECK (status IN ('registrada', 'activa', 'retirada')),
                activated_at timestamptz NULL,
                activated_by bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                CONSTRAINT model_versions_thresholds_check
                    CHECK (0 < threshold_medium AND threshold_medium < threshold_high AND threshold_high < 1),
                CONSTRAINT model_versions_activation_check
                    CHECK (status <> 'activa' OR (auc_validation >= 0.75 AND brier_score IS NOT NULL))
            );
            CREATE UNIQUE INDEX model_versions_single_active ON model_versions ((true)) WHERE status = 'activa';

            CREATE TABLE risk_variable_sets (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                patient_id bigint NOT NULL,
                age_group varchar(6) NOT NULL CHECK (age_group IN ('menor', 'adulto')),
                age_years smallint NOT NULL CONSTRAINT risk_variable_sets_age_check CHECK (age_years BETWEEN 0 AND 130),
                clinical jsonb NOT NULL DEFAULT '{}',
                behavioral jsonb NOT NULL DEFAULT '{}',
                sociodemographic jsonb NOT NULL DEFAULT '{}',
                is_complete boolean NOT NULL DEFAULT false,
                clinical_captured_by bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
                clinical_captured_at timestamptz NULL,
                sociodemographic_captured_by bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
                sociodemographic_captured_at timestamptz NULL,
                supersedes_id bigint NULL,
                locked_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id),
                FOREIGN KEY (tenant_id, patient_id) REFERENCES patients (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, supersedes_id) REFERENCES risk_variable_sets (tenant_id, id) ON DELETE RESTRICT
            );
            CREATE INDEX risk_variable_sets_patient_idx ON risk_variable_sets (tenant_id, patient_id, created_at DESC);
            CREATE INDEX risk_variable_sets_sociodemographic_idx ON risk_variable_sets USING gin (sociodemographic jsonb_path_ops);

            CREATE OR REPLACE FUNCTION fn_risk_variable_sets_locked() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF OLD.locked_at IS NULL THEN RETURN NEW; END IF;
              IF current_setting('app.patient_merge', true) = 'on'
                 AND (to_jsonb(NEW) - 'patient_id' - 'updated_at') = (to_jsonb(OLD) - 'patient_id' - 'updated_at') THEN
                RETURN NEW;                                                                          -- DI-21
              END IF;
              RAISE EXCEPTION 'immutable_row: locked risk_variable_sets' USING ERRCODE = '55000';
            END $$;
            CREATE TRIGGER trg_risk_variable_sets_locked BEFORE UPDATE ON risk_variable_sets
                FOR EACH ROW EXECUTE FUNCTION fn_risk_variable_sets_locked();

            CREATE TABLE risk_predictions (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                patient_id bigint NOT NULL,
                risk_variable_set_id bigint NOT NULL,
                model_version_id bigint NOT NULL REFERENCES model_versions (id) ON DELETE RESTRICT,
                probability numeric(5,4) NOT NULL CHECK (probability BETWEEN 0 AND 1),
                level varchar(5) NOT NULL CHECK (level IN ('bajo', 'medio', 'alto')),
                confidence numeric(5,4) NOT NULL CHECK (confidence BETWEEN 0 AND 1),
                threshold_medium numeric(4,3) NOT NULL,
                threshold_high numeric(4,3) NOT NULL,
                base_value numeric(10,6) NOT NULL,
                explanation_global jsonb NOT NULL,
                explanation_individual jsonb NOT NULL,
                status varchar(11) NOT NULL DEFAULT 'vigente' CHECK (status IN ('vigente', 'reemplazada', 'vencida')),
                requested_by bigint NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                predicted_at timestamptz NOT NULL,
                expires_at timestamptz NOT NULL,
                request_ref uuid NOT NULL UNIQUE,
                latency_ms integer NOT NULL CHECK (latency_ms >= 0),
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (tenant_id, id),
                CONSTRAINT risk_predictions_id_level_key UNIQUE (id, level),
                FOREIGN KEY (tenant_id, patient_id) REFERENCES patients (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, risk_variable_set_id) REFERENCES risk_variable_sets (tenant_id, id) ON DELETE RESTRICT,
                CONSTRAINT risk_predictions_level_thresholds_check CHECK (level = CASE
                    WHEN probability >= threshold_high THEN 'alto'
                    WHEN probability >= threshold_medium THEN 'medio'
                    ELSE 'bajo' END),
                CONSTRAINT risk_predictions_expires_check CHECK (expires_at = predicted_at + interval '12 months')
            );
            CREATE UNIQUE INDEX risk_predictions_current_unique ON risk_predictions (tenant_id, patient_id) WHERE status = 'vigente';
            CREATE INDEX risk_predictions_patient_idx ON risk_predictions (tenant_id, patient_id, predicted_at DESC);
            CREATE INDEX risk_predictions_idempotency_idx
                ON risk_predictions (tenant_id, risk_variable_set_id, model_version_id, predicted_at DESC);

            CREATE OR REPLACE FUNCTION fn_risk_predictions_allowed_columns() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                IF current_setting('app.retention_delete', true) = 'on' THEN RETURN OLD; END IF;  -- DI-21
                RAISE EXCEPTION 'immutable_row: DELETE on risk_predictions' USING ERRCODE = '55000';
              END IF;
              IF NEW.patient_id IS DISTINCT FROM OLD.patient_id
                 AND current_setting('app.patient_merge', true) IS DISTINCT FROM 'on' THEN          -- DI-21
                RAISE EXCEPTION 'immutable_row: patient_id on risk_predictions' USING ERRCODE = '55000';
              END IF;
              IF (to_jsonb(NEW) - 'status' - 'patient_id') IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'patient_id') THEN
                RAISE EXCEPTION 'immutable_row: UPDATE on risk_predictions' USING ERRCODE = '55000';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_risk_predictions_allowed_columns BEFORE UPDATE OR DELETE ON risk_predictions
                FOR EACH ROW EXECUTE FUNCTION fn_risk_predictions_allowed_columns();

            CREATE TABLE risk_alerts (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                risk_prediction_id bigint NOT NULL UNIQUE,
                prediction_level varchar(5) NOT NULL DEFAULT 'alto' CHECK (prediction_level = 'alto'),
                patient_id bigint NOT NULL,
                dentist_id bigint NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                status varchar(10) NOT NULL DEFAULT 'abierta' CHECK (status IN ('abierta', 'reconocida')),
                action varchar(15) NULL CHECK (action IN ('plan_preventivo', 'cita_control', 'justificacion')),
                treatment_plan_id bigint NULL,
                appointment_id bigint NULL,
                justification varchar(1000) NULL,
                acknowledged_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id),
                FOREIGN KEY (risk_prediction_id, prediction_level) REFERENCES risk_predictions (id, level) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, risk_prediction_id) REFERENCES risk_predictions (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, patient_id) REFERENCES patients (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, treatment_plan_id) REFERENCES treatment_plans (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, appointment_id) REFERENCES appointments (tenant_id, id) ON DELETE RESTRICT,
                CONSTRAINT risk_alerts_acknowledged_action_check
                    CHECK (status <> 'reconocida' OR (action IS NOT NULL AND acknowledged_at IS NOT NULL)),
                CONSTRAINT risk_alerts_plan_check CHECK (action IS DISTINCT FROM 'plan_preventivo' OR treatment_plan_id IS NOT NULL),
                CONSTRAINT risk_alerts_appointment_check CHECK (action IS DISTINCT FROM 'cita_control' OR appointment_id IS NOT NULL),
                CONSTRAINT risk_alerts_justification_check
                    CHECK (action IS DISTINCT FROM 'justificacion' OR (justification IS NOT NULL AND char_length(justification) >= 20))
            );
            CREATE INDEX risk_alerts_dentist_status_idx ON risk_alerts (tenant_id, dentist_id, status, created_at);

            CREATE TABLE external_call_logs (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                tenant_id bigint NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                service varchar(5) NOT NULL CHECK (service IN ('ml', 'ia', 'smtp', 's3')),
                operation varchar(60) NOT NULL,
                outcome varchar(20) NOT NULL CHECK (outcome IN ('ok', 'timeout', 'error', 'respuesta_invalida', 'cb_abierto')),
                latency_ms integer NOT NULL CHECK (latency_ms >= 0),
                http_status smallint NULL,
                breaker_state varchar(10) NOT NULL CHECK (breaker_state IN ('cerrado', 'abierto', 'semiabierto')),
                correlation_id uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL
            );
            CREATE INDEX external_call_logs_service_idx ON external_call_logs (service, created_at DESC);

            ALTER TABLE appointments ADD COLUMN risk_alert_id bigint NULL;
            ALTER TABLE appointments ADD CONSTRAINT appointments_risk_alert_fk
              FOREIGN KEY (tenant_id, risk_alert_id) REFERENCES risk_alerts (tenant_id, id) ON DELETE RESTRICT;
            ALTER TABLE treatment_plans ADD COLUMN risk_alert_id bigint NULL;
            ALTER TABLE treatment_plans ADD CONSTRAINT treatment_plans_risk_alert_fk
              FOREIGN KEY (tenant_id, risk_alert_id) REFERENCES risk_alerts (tenant_id, id) ON DELETE RESTRICT;
            SQL);

        foreach (self::TABLES as $table) {
            RowLevelSecurity::enable($table);
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE treatment_plans DROP COLUMN IF EXISTS risk_alert_id;
            ALTER TABLE appointments DROP COLUMN IF EXISTS risk_alert_id;
            DROP TABLE IF EXISTS external_call_logs;
            DROP TABLE IF EXISTS risk_alerts;
            DROP TABLE IF EXISTS risk_predictions;
            DROP FUNCTION IF EXISTS fn_risk_predictions_allowed_columns();
            DROP TABLE IF EXISTS risk_variable_sets;
            DROP FUNCTION IF EXISTS fn_risk_variable_sets_locked();
            DROP TABLE IF EXISTS model_versions;
            SQL);
    }
};
