<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Excepción `app.arco_erasure` a la inmutabilidad de Risk (TASK-104; SDD §5.11, DI-21; RF-184,
 * RN-69). Solo `ArcoCancellationService` la fija, con `set_config(…, 'on', true)` dentro de su
 * transacción, y solo permite eliminar los datos de la finalidad opcional (d):
 *
 * - `risk_variable_sets`: `sociodemographic` pasa a `{}`; ninguna otra columna cambia.
 * - `risk_predictions`: el `value` de las variables sociodemográficas de `explanation_individual`
 *   pasa a `null`; la variable, su contribución y las demás columnas no cambian.
 */
return new class extends Migration
{
    private const SOCIODEMOGRAPHIC_FEATURES = "'education_level', 'employment_status', 'family_structure'";

    public function up(): void
    {
        $features = self::SOCIODEMOGRAPHIC_FEATURES;

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION fn_risk_variable_sets_locked() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
              IF OLD.locked_at IS NULL THEN RETURN NEW; END IF;
              IF current_setting('app.patient_merge', true) = 'on'
                 AND (to_jsonb(NEW) - 'patient_id' - 'updated_at') = (to_jsonb(OLD) - 'patient_id' - 'updated_at') THEN
                RETURN NEW;                                                                          -- DI-21
              END IF;
              IF current_setting('app.arco_erasure', true) = 'on'
                 AND NEW.sociodemographic = '{}'::jsonb
                 AND (to_jsonb(NEW) - 'sociodemographic' - 'updated_at') = (to_jsonb(OLD) - 'sociodemographic' - 'updated_at') THEN
                RETURN NEW;                                                                          -- DI-21, RF-184
              END IF;
              RAISE EXCEPTION 'immutable_row: locked risk_variable_sets' USING ERRCODE = '55000';
            END \$\$;

            CREATE OR REPLACE FUNCTION fn_risk_explanation_erased(old_items jsonb, new_items jsonb) RETURNS boolean
            LANGUAGE sql IMMUTABLE AS \$\$
              SELECT jsonb_array_length(old_items) = jsonb_array_length(new_items)
                 AND NOT EXISTS (
                   SELECT 1
                   FROM jsonb_array_elements(old_items) WITH ORDINALITY AS o(item, position)
                   JOIN jsonb_array_elements(new_items) WITH ORDINALITY AS n(item, position) USING (position)
                   WHERE (n.item - 'value') IS DISTINCT FROM (o.item - 'value')
                      OR (n.item -> 'value' IS DISTINCT FROM o.item -> 'value'
                          AND NOT (n.item -> 'value' = 'null'::jsonb AND o.item ->> 'feature' IN ({$features})))
                 )
            \$\$;

            CREATE OR REPLACE FUNCTION fn_risk_predictions_allowed_columns() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                IF current_setting('app.retention_delete', true) = 'on' THEN RETURN OLD; END IF;  -- DI-21
                RAISE EXCEPTION 'immutable_row: DELETE on risk_predictions' USING ERRCODE = '55000';
              END IF;
              IF NEW.patient_id IS DISTINCT FROM OLD.patient_id
                 AND current_setting('app.patient_merge', true) IS DISTINCT FROM 'on' THEN          -- DI-21
                RAISE EXCEPTION 'immutable_row: patient_id on risk_predictions' USING ERRCODE = '55000';
              END IF;
              IF NEW.explanation_individual IS DISTINCT FROM OLD.explanation_individual
                 AND (current_setting('app.arco_erasure', true) IS DISTINCT FROM 'on'                 -- DI-21, RF-184
                      OR NOT fn_risk_explanation_erased(OLD.explanation_individual, NEW.explanation_individual)) THEN
                RAISE EXCEPTION 'immutable_row: explanation_individual on risk_predictions' USING ERRCODE = '55000';
              END IF;
              IF (to_jsonb(NEW) - 'status' - 'patient_id' - 'explanation_individual')
                 IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'patient_id' - 'explanation_individual') THEN
                RAISE EXCEPTION 'immutable_row: UPDATE on risk_predictions' USING ERRCODE = '55000';
              END IF;
              RETURN NEW;
            END \$\$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_risk_variable_sets_locked() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF OLD.locked_at IS NULL THEN RETURN NEW; END IF;
              IF current_setting('app.patient_merge', true) = 'on'
                 AND (to_jsonb(NEW) - 'patient_id' - 'updated_at') = (to_jsonb(OLD) - 'patient_id' - 'updated_at') THEN
                RETURN NEW;                                                                          -- DI-21
              END IF;
              RAISE EXCEPTION 'immutable_row: locked risk_variable_sets' USING ERRCODE = '55000';
            END $$;

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

            DROP FUNCTION IF EXISTS fn_risk_explanation_erased(jsonb, jsonb);
            SQL);
    }
};
