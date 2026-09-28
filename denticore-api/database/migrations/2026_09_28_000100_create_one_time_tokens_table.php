<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `one_time_tokens` (TASK-015; SDD §2.4, DI-15; DD-15, DD-22, RNF-111, RNF-112): tokens de un
 * solo uso de todos los propósitos. Se guarda solo su SHA-256; tenant_id nulable (tokens de
 * plataforma).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE one_time_tokens (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                purpose varchar(30) NOT NULL CHECK (purpose IN (
                    'invitacion', 'restablecimiento', 'confirmacion_cita', 'otp_presupuesto',
                    'presupuesto_compartido', 'encuesta', 'verificacion_correo')),
                token_hash char(64) NOT NULL UNIQUE,
                tokenable_type varchar(60) NOT NULL,
                tokenable_id bigint NOT NULL,
                expires_at timestamptz NOT NULL,
                used_at timestamptz NULL,
                invalidated_at timestamptz NULL,
                failed_attempts smallint NOT NULL DEFAULT 0,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL
            );

            CREATE INDEX one_time_tokens_active_idx ON one_time_tokens (tokenable_type, tokenable_id, purpose)
                WHERE used_at IS NULL AND invalidated_at IS NULL;
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS one_time_tokens');
    }
};
