<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Esquema de identidad, etapa de expansión (TASK-026; SDD §2.4, Plan §2.3; DI-03, DD-15,
 * DD-22, RN-75, RF-043, RF-047, RNF-132).
 *
 * - Expande `users`: estado en español (poblado desde `is_active`), oficial de datos (el primer
 *   `clinic_admin` de cada clínica), COP, especialidad, RNE, 2FA, bloqueo y fechas de sesión.
 *   `password` pasa a nulable (usuario `pendiente_activacion`, DD-22).
 * - Los odontólogos existentes reciben un COP sintético (solo hay datos sintéticos, RES-08).
 * - Crea `user_password_histories` y `two_factor_recovery_codes`.
 * - Amplía `personal_access_tokens` con clínica, IP, agente y dispositivo.
 *
 * `is_active` se elimina en la contracción (TASK-038).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE users
                ADD COLUMN status varchar(25) NOT NULL DEFAULT 'pendiente_activacion',
                ADD COLUMN is_data_officer boolean NOT NULL DEFAULT false,
                ADD COLUMN cop_number varchar(10) NULL,
                ADD COLUMN specialty varchar(100) NULL,
                ADD COLUMN rne_number varchar(10) NULL,
                ADD COLUMN two_factor_secret text NULL,
                ADD COLUMN two_factor_confirmed_at timestamptz NULL,
                ADD COLUMN two_factor_reset_required boolean NOT NULL DEFAULT false,
                ADD COLUMN failed_login_count smallint NOT NULL DEFAULT 0,
                ADD COLUMN locked_until timestamptz NULL,
                ADD COLUMN last_login_at timestamptz NULL,
                ADD COLUMN password_changed_at timestamptz NULL,
                ADD COLUMN deactivated_at timestamptz NULL;

            ALTER TABLE users ALTER COLUMN password DROP NOT NULL;

            UPDATE users SET status = CASE WHEN is_active THEN 'activo' ELSE 'inactivo' END,
                             deactivated_at = CASE WHEN is_active THEN NULL ELSE coalesce(updated_at, now()) END;

            UPDATE users u SET is_data_officer = true
             WHERE u.role = 'clinic_admin'
               AND u.id = (SELECT min(f.id) FROM users f WHERE f.tenant_id = u.tenant_id AND f.role = 'clinic_admin');

            -- COP sintético de 5 dígitos para los odontólogos de los datos de demostración.
            UPDATE users SET cop_number = lpad(id::text, 5, '0')
             WHERE role = 'dentist' AND cop_number IS NULL;

            UPDATE users SET email = lower(email) WHERE email <> lower(email);

            ALTER TABLE users ADD CONSTRAINT users_status_check
                CHECK (status IN ('pendiente_activacion', 'activo', 'bloqueado_temporal', 'inactivo'));
            ALTER TABLE users ADD CONSTRAINT users_data_officer_check
                CHECK (NOT is_data_officer OR role = 'clinic_admin');
            ALTER TABLE users ADD CONSTRAINT users_dentist_cop_check
                CHECK (role <> 'dentist' OR cop_number IS NOT NULL);
            ALTER TABLE users ADD CONSTRAINT users_platform_role_check
                CHECK ((role = 'super_admin') = (tenant_id IS NULL));
            ALTER TABLE users ADD CONSTRAINT users_tenant_cop_unique UNIQUE (tenant_id, cop_number);
            CREATE INDEX users_tenant_role_status_idx ON users (tenant_id, role, status);

            CREATE TABLE user_password_histories (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                user_id bigint NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                password_hash varchar(255) NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL
            );
            CREATE INDEX user_password_histories_user_idx ON user_password_histories (user_id, created_at DESC);

            CREATE TABLE two_factor_recovery_codes (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                user_id bigint NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                code_hash char(64) NOT NULL,
                used_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL
            );
            CREATE INDEX two_factor_recovery_codes_user_idx ON two_factor_recovery_codes (user_id);

            ALTER TABLE personal_access_tokens
                ADD COLUMN tenant_id bigint NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                ADD COLUMN ip_address inet NULL,
                ADD COLUMN user_agent varchar(300) NULL,
                ADD COLUMN device_label varchar(100) NULL;
            CREATE INDEX personal_access_tokens_tokenable_last_used_idx
                ON personal_access_tokens (tokenable_id, last_used_at);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS personal_access_tokens_tokenable_last_used_idx;
            ALTER TABLE personal_access_tokens
                DROP COLUMN IF EXISTS device_label,
                DROP COLUMN IF EXISTS user_agent,
                DROP COLUMN IF EXISTS ip_address,
                DROP COLUMN IF EXISTS tenant_id;

            DROP TABLE IF EXISTS two_factor_recovery_codes;
            DROP TABLE IF EXISTS user_password_histories;

            DROP INDEX IF EXISTS users_tenant_role_status_idx;
            ALTER TABLE users DROP CONSTRAINT IF EXISTS users_tenant_cop_unique;
            ALTER TABLE users DROP CONSTRAINT IF EXISTS users_platform_role_check;
            ALTER TABLE users DROP CONSTRAINT IF EXISTS users_dentist_cop_check;
            ALTER TABLE users DROP CONSTRAINT IF EXISTS users_data_officer_check;
            ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check;

            -- Un usuario pendiente no tiene contraseña: el esquema anterior la exige.
            UPDATE users SET password = '' WHERE password IS NULL;
            ALTER TABLE users ALTER COLUMN password SET NOT NULL;

            ALTER TABLE users
                DROP COLUMN IF EXISTS deactivated_at,
                DROP COLUMN IF EXISTS password_changed_at,
                DROP COLUMN IF EXISTS last_login_at,
                DROP COLUMN IF EXISTS locked_until,
                DROP COLUMN IF EXISTS failed_login_count,
                DROP COLUMN IF EXISTS two_factor_reset_required,
                DROP COLUMN IF EXISTS two_factor_confirmed_at,
                DROP COLUMN IF EXISTS two_factor_secret,
                DROP COLUMN IF EXISTS rne_number,
                DROP COLUMN IF EXISTS specialty,
                DROP COLUMN IF EXISTS cop_number,
                DROP COLUMN IF EXISTS is_data_officer,
                DROP COLUMN IF EXISTS status;
            SQL);
    }
};
