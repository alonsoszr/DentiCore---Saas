<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Esquema de plataforma y clínicas, etapa de expansión (TASK-021; SDD §2.3, Plan §2.3; DI-02,
 * DI-03, DI-04, DD-16, DD-22, DD-23, DD-29, RF-013, RF-014, RNF-132).
 *
 * - Crea `subscription_plans` (semilla DD-16), `platform_settings`, `clinic_settings` (BT, 1:1)
 *   y `document_sequences`.
 * - Expande `tenants` con las columnas de SDD §2.3. `legal_name`, `ruc` y `address` quedan
 *   nulables hasta que el alta con invitación los exija (TASK-023, etapa "cambiar").
 * - Puebla el plan como FK desde `subscription_plan`, una fila de `clinic_settings` por clínica
 *   desde `settings` y sus dos secuencias de documentos.
 * - `status` se convierte en el mismo lugar a los valores en español de DI-03: no hay otro nombre
 *   de columna en el SDD para que convivan ambos, y solo existen datos sintéticos (P-07).
 *
 * `subscription_plan` y `settings` se eliminan en la contracción (TASK-038).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE subscription_plans (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                code varchar(20) NOT NULL UNIQUE CHECK (code IN ('basic', 'pro', 'enterprise')),
                name varchar(60) NOT NULL,
                max_dentists smallint NULL CHECK (max_dentists > 0),
                includes_ai boolean NOT NULL,
                includes_risk boolean NOT NULL,
                includes_analytics boolean NOT NULL,
                ai_monthly_quota integer NULL CHECK (ai_monthly_quota >= 0),
                rate_limit_per_minute integer NOT NULL DEFAULT 1200,
                monthly_price_pen numeric(12,2) NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL
            );

            -- DD-16; cuota de IA con el supuesto de PQ-02; precio nulo por PQ-01.
            INSERT INTO subscription_plans
                (code, name, max_dentists, includes_ai, includes_risk, includes_analytics, ai_monthly_quota)
            VALUES
                ('basic', 'Basic', 2, false, false, false, NULL),
                ('pro', 'Pro', 10, true, true, false, 300),
                ('enterprise', 'Enterprise', NULL, true, true, true, 1500);

            CREATE TABLE platform_settings (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                key varchar(80) NOT NULL UNIQUE,
                value jsonb NOT NULL,
                updated_by bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL
            );

            -- Claves semilla de SDD §2.3 que tienen valor definido. `ai.provider`, `ai.model`,
            -- `ai.prompt_version` y `perf.route_p95_ms` no lo tienen: se agregan en la tarea que
            -- los usa (MS-12, MS-14); `consent.current_version` con la plantilla v1 (TASK-035).
            INSERT INTO platform_settings (key, value) VALUES
                ('igv_rate', '0.18'),
                ('ml.timeout_ms', '3000'),
                ('ml.cb_failures', '5'),
                ('ml.cb_open_seconds', '60'),
                ('ai.timeout_s', '15'),
                ('perf.error_rate_pct', '1'),
                ('perf.ai_failure_pct', '20'),
                ('perf.queue_wait_s', '600'),
                ('perf.tenant_share_pct', '50');

            -- tenants: expansión.
            ALTER TABLE tenants
                ADD COLUMN legal_name varchar(200) NULL,
                ADD COLUMN ruc char(11) NULL,
                ADD COLUMN address varchar(200) NULL,
                ADD COLUMN phone varchar(20) NULL,
                ADD COLUMN contact_email varchar(180) NULL,
                ADD COLUMN logo_file_id bigint NULL REFERENCES stored_files (id) ON DELETE RESTRICT,
                ADD COLUMN subscription_plan_id bigint NULL REFERENCES subscription_plans (id) ON DELETE RESTRICT,
                ADD COLUMN status_reason varchar(500) NULL,
                ADD COLUMN suspended_at timestamptz NULL,
                ADD COLUMN cancelled_at timestamptz NULL,
                ADD COLUMN purged_at timestamptz NULL,
                ADD COLUMN timezone varchar(40) NOT NULL DEFAULT 'America/Lima';

            UPDATE tenants t
               SET subscription_plan_id = p.id
              FROM subscription_plans p
             WHERE p.code = t.subscription_plan AND t.subscription_plan_id IS NULL;

            ALTER TABLE tenants ALTER COLUMN subscription_plan_id SET NOT NULL;

            -- status en español (DI-03) con 'eliminada' de SRS §5.5.7.
            ALTER TABLE tenants DROP CONSTRAINT tenants_status_check;
            UPDATE tenants SET status = CASE status
                WHEN 'active' THEN 'activa'
                WHEN 'suspended' THEN 'suspendida'
                WHEN 'cancelled' THEN 'cancelada'
                ELSE status END;
            ALTER TABLE tenants ALTER COLUMN status SET DEFAULT 'activa';
            ALTER TABLE tenants ADD CONSTRAINT tenants_status_check
                CHECK (status IN ('activa', 'suspendida', 'cancelada', 'eliminada'));

            ALTER TABLE tenants ALTER COLUMN slug TYPE varchar(50);
            ALTER TABLE tenants ADD CONSTRAINT tenants_slug_format_check
                CHECK (slug ~ '^[a-z0-9]([a-z0-9-]{1,48})[a-z0-9]$');
            ALTER TABLE tenants ADD CONSTRAINT tenants_ruc_format_check
                CHECK (ruc ~ '^(10|20)[0-9]{9}$');
            ALTER TABLE tenants ADD CONSTRAINT tenants_ruc_unique UNIQUE (ruc);
            CREATE INDEX tenants_status_plan_idx ON tenants (status, subscription_plan_id);
            CREATE INDEX tenants_name_trgm_idx ON tenants USING gin (name gin_trgm_ops);

            -- DD-03, DD-29: el código de acceso no cambia nunca.
            CREATE OR REPLACE FUNCTION fn_tenants_slug_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.slug IS DISTINCT FROM OLD.slug THEN
                RAISE EXCEPTION 'immutable_slug: tenants.slug' USING ERRCODE = '55000';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER trg_tenants_slug_immutable BEFORE UPDATE OF slug ON tenants
                FOR EACH ROW EXECUTE FUNCTION fn_tenants_slug_immutable();

            CREATE TABLE clinic_settings (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL UNIQUE REFERENCES tenants (id) ON DELETE RESTRICT,
                prices_include_igv boolean NOT NULL DEFAULT true,
                discount_cap_pct numeric(5,2) NOT NULL DEFAULT 10.00
                    CHECK (discount_cap_pct >= 0 AND discount_cap_pct <= 100),
                budget_validity_days smallint NOT NULL DEFAULT 30
                    CHECK (budget_validity_days BETWEEN 1 AND 180),
                portal_cancel_hours smallint NOT NULL DEFAULT 24
                    CHECK (portal_cancel_hours BETWEEN 0 AND 72),
                self_booking_enabled boolean NOT NULL DEFAULT false,
                ai_enabled boolean NOT NULL DEFAULT false,
                ai_enabled_at timestamptz NULL,
                ai_enabled_by bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
                budget_terms text NULL CHECK (char_length(budget_terms) <= 2000),
                complaints_book_url varchar(300) NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id)
            );

            -- Una fila por clínica; las claves del jsonb heredado con el mismo nombre se conservan.
            INSERT INTO clinic_settings (tenant_id, prices_include_igv, discount_cap_pct,
                    budget_validity_days, portal_cancel_hours, self_booking_enabled, budget_terms)
            SELECT t.id,
                   coalesce((t.settings ->> 'prices_include_igv')::boolean, true),
                   coalesce((t.settings ->> 'discount_cap_pct')::numeric, 10.00),
                   coalesce((t.settings ->> 'budget_validity_days')::smallint, 30),
                   coalesce((t.settings ->> 'portal_cancel_hours')::smallint, 24),
                   coalesce((t.settings ->> 'self_booking_enabled')::boolean, false),
                   t.settings ->> 'budget_terms'
              FROM tenants t
             WHERE NOT EXISTS (SELECT 1 FROM clinic_settings c WHERE c.tenant_id = t.id);

            CREATE TABLE document_sequences (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                doc_type varchar(20) NOT NULL CHECK (doc_type IN ('presupuesto', 'recibo')),
                last_value bigint NOT NULL DEFAULT 0 CHECK (last_value >= 0),
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, doc_type)
            );

            INSERT INTO document_sequences (tenant_id, doc_type)
            SELECT t.id, d.doc_type
              FROM tenants t
             CROSS JOIN (VALUES ('presupuesto'), ('recibo')) AS d (doc_type)
            ON CONFLICT (tenant_id, doc_type) DO NOTHING;
            SQL);

        // Después del poblado: con FORCE ROW LEVEL SECURITY ni el propietario ve filas sin
        // app.tenant_id.
        RowLevelSecurity::enable('clinic_settings');
        RowLevelSecurity::enable('document_sequences');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('document_sequences');
        RowLevelSecurity::disable('clinic_settings');

        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS document_sequences;
            DROP TABLE IF EXISTS clinic_settings;

            DROP TRIGGER IF EXISTS trg_tenants_slug_immutable ON tenants;
            DROP FUNCTION IF EXISTS fn_tenants_slug_immutable();

            DROP INDEX IF EXISTS tenants_name_trgm_idx;
            DROP INDEX IF EXISTS tenants_status_plan_idx;
            ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_ruc_unique;
            ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_ruc_format_check;
            ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_slug_format_check;
            ALTER TABLE tenants ALTER COLUMN slug TYPE varchar(150);

            ALTER TABLE tenants DROP CONSTRAINT tenants_status_check;
            UPDATE tenants SET status = CASE status
                WHEN 'activa' THEN 'active'
                WHEN 'suspendida' THEN 'suspended'
                ELSE 'cancelled' END;
            ALTER TABLE tenants ALTER COLUMN status SET DEFAULT 'active';
            ALTER TABLE tenants ADD CONSTRAINT tenants_status_check
                CHECK (status IN ('active', 'suspended', 'cancelled'));

            ALTER TABLE tenants
                DROP COLUMN IF EXISTS timezone,
                DROP COLUMN IF EXISTS purged_at,
                DROP COLUMN IF EXISTS cancelled_at,
                DROP COLUMN IF EXISTS suspended_at,
                DROP COLUMN IF EXISTS status_reason,
                DROP COLUMN IF EXISTS subscription_plan_id,
                DROP COLUMN IF EXISTS logo_file_id,
                DROP COLUMN IF EXISTS contact_email,
                DROP COLUMN IF EXISTS phone,
                DROP COLUMN IF EXISTS address,
                DROP COLUMN IF EXISTS ruc,
                DROP COLUMN IF EXISTS legal_name;

            DROP TABLE IF EXISTS platform_settings;
            DROP TABLE IF EXISTS subscription_plans;
            SQL);
    }
};
