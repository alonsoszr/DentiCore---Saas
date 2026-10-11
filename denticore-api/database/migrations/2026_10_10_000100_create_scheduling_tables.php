<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Esquema de agenda (TASK-064; SDD §2.10; DD-09, DD-24, DI-09, RN-46, RN-47, RN-74; estados de
 * SRS §5.5.4).
 *
 * - `work_schedules` (BT): franjas por odontólogo y día ISO; `hours` (timerange, DI-09) y
 *   `validity` (daterange) generados permiten el EXCLUDE que impide franjas solapadas (RF-142).
 * - `schedule_blocks` (BT): bloqueos por odontólogo o de toda la clínica (`dentist_id` NULL).
 * - `appointment_types` (BT) y `appointments` (BT): `period` (tstzrange generado) y dos EXCLUDE
 *   sobre los estados activos, por odontólogo (RN-46) y por paciente (RN-74). El 23P01 se traduce
 *   a 409 (DD-09).
 * - Expansión de `attentions` con `appointment_id` (UNIQUE, CUS-50).
 *
 * `appointments.risk_alert_id` se agrega en TASK-078 y `periodic_control_id` en TASK-151.
 */
return new class extends Migration
{
    private const TABLES = ['work_schedules', 'schedule_blocks', 'appointment_types', 'appointments'];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE work_schedules (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                dentist_id bigint NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                weekday smallint NOT NULL CONSTRAINT work_schedules_weekday_check CHECK (weekday BETWEEN 1 AND 7),
                start_time time NOT NULL,
                end_time time NOT NULL,
                hours timerange GENERATED ALWAYS AS (timerange(start_time, end_time, '[)')) STORED,
                valid_from date NOT NULL,
                valid_until date NULL,
                validity daterange GENERATED ALWAYS AS (daterange(valid_from, valid_until, '[]')) STORED,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id),
                CONSTRAINT work_schedules_times_check CHECK (start_time < end_time),
                CONSTRAINT work_schedules_validity_check CHECK (valid_until IS NULL OR valid_until >= valid_from),
                CONSTRAINT work_schedules_no_overlap
                    EXCLUDE USING gist (tenant_id WITH =, dentist_id WITH =, weekday WITH =, validity WITH &&, hours WITH &&)
            );

            CREATE TABLE schedule_blocks (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                dentist_id bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
                period tstzrange NOT NULL CONSTRAINT schedule_blocks_period_check CHECK (NOT isempty(period)),
                kind varchar(15) NOT NULL CHECK (kind IN ('vacaciones', 'capacitacion', 'feriado', 'cierre', 'otro')),
                reason varchar(200) NOT NULL,
                created_by bigint NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id)
            );
            CREATE INDEX schedule_blocks_period_idx ON schedule_blocks USING gist (tenant_id, dentist_id, period);

            CREATE TABLE appointment_types (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                name varchar(80) NOT NULL,
                default_duration_min smallint NOT NULL CONSTRAINT appointment_types_duration_check
                    CHECK (default_duration_min BETWEEN 10 AND 240 AND default_duration_min % 5 = 0),
                color char(7) NOT NULL CONSTRAINT appointment_types_color_check CHECK (color ~ '^#[0-9A-Fa-f]{6}$'),
                portal_visible boolean NOT NULL DEFAULT false,
                is_active boolean NOT NULL DEFAULT true,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id),
                CONSTRAINT appointment_types_tenant_id_name_key UNIQUE (tenant_id, name)
            );

            CREATE TABLE appointments (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                patient_id bigint NOT NULL,
                dentist_id bigint NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                appointment_type_id bigint NOT NULL,
                starts_at timestamptz NOT NULL,
                ends_at timestamptz NOT NULL,
                period tstzrange GENERATED ALWAYS AS (tstzrange(starts_at, ends_at, '[)')) STORED,
                status varchar(15) NOT NULL DEFAULT 'programada'
                    CHECK (status IN ('programada', 'confirmada', 'en_atencion', 'atendida', 'cancelada', 'inasistencia')),
                origin varchar(10) NOT NULL CHECK (origin IN ('clinica', 'portal')),
                confirmed_at timestamptz NULL,
                confirmation_channel varchar(10) NULL CHECK (confirmation_channel IN ('enlace', 'portal', 'telefono')),
                reconfirmation_pending boolean NOT NULL DEFAULT false,
                checked_in_at timestamptz NULL,
                cancelled_at timestamptz NULL,
                cancelled_by bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
                cancel_reason varchar(10) NULL CHECK (cancel_reason IN ('paciente', 'clinica', 'otro')),
                cancel_detail varchar(300) NULL,
                no_show_marked_at timestamptz NULL,
                created_by bigint NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id),
                FOREIGN KEY (tenant_id, patient_id) REFERENCES patients (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, appointment_type_id) REFERENCES appointment_types (tenant_id, id) ON DELETE RESTRICT,
                CONSTRAINT appointments_ends_after_starts_check CHECK (ends_at > starts_at),
                CONSTRAINT appointments_cancelled_reason_required CHECK (status <> 'cancelada' OR cancel_reason IS NOT NULL)
            );
            ALTER TABLE appointments ADD CONSTRAINT appointments_duration_chk
              CHECK (extract(epoch FROM ends_at - starts_at) BETWEEN 600 AND 14400
                     AND (extract(epoch FROM ends_at - starts_at)::int % 300) = 0);
            ALTER TABLE appointments ADD CONSTRAINT appointments_no_overlap_dentist
              EXCLUDE USING gist (tenant_id WITH =, dentist_id WITH =, period WITH &&)
              WHERE (status IN ('programada', 'confirmada', 'en_atencion'));
            ALTER TABLE appointments ADD CONSTRAINT appointments_no_overlap_patient
              EXCLUDE USING gist (tenant_id WITH =, patient_id WITH =, period WITH &&)
              WHERE (status IN ('programada', 'confirmada', 'en_atencion'));
            CREATE INDEX appointments_starts_idx ON appointments (tenant_id, starts_at);
            CREATE INDEX appointments_dentist_starts_idx ON appointments (tenant_id, dentist_id, starts_at);
            CREATE INDEX appointments_status_ends_idx ON appointments (tenant_id, status, ends_at);
            CREATE INDEX appointments_patient_starts_idx ON appointments (tenant_id, patient_id, starts_at DESC);

            ALTER TABLE attentions ADD COLUMN appointment_id bigint NULL CONSTRAINT attentions_appointment_id_key UNIQUE;
            ALTER TABLE attentions ADD CONSTRAINT attentions_appointment_fk
              FOREIGN KEY (tenant_id, appointment_id) REFERENCES appointments (tenant_id, id) ON DELETE RESTRICT;
            SQL);

        foreach (self::TABLES as $table) {
            RowLevelSecurity::enable($table);
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE attentions DROP COLUMN IF EXISTS appointment_id;
            DROP TABLE IF EXISTS appointments;
            DROP TABLE IF EXISTS appointment_types;
            DROP TABLE IF EXISTS schedule_blocks;
            DROP TABLE IF EXISTS work_schedules;
            SQL);
    }
};
