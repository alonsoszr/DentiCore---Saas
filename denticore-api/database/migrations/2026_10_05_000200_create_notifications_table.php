<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `notifications` (TASK-022; SDD §2.10, §4.8; DD-10, RF-155, RNF-110). Base BU con `tenant_id`
 * nulable (avisos de plataforma), por eso sin RLS. El evento se valida contra el catálogo de
 * §4.8 en PHP (`NotificationEvent`).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE notifications (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                channel varchar(10) NOT NULL CHECK (channel IN ('correo', 'in_app')),
                event varchar(60) NOT NULL,
                recipient_user_id bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
                recipient_patient_id bigint NULL REFERENCES patients (id) ON DELETE RESTRICT,
                recipient_email_hash char(64) NULL,
                payload jsonb NOT NULL,
                dedupe_key varchar(150) NULL UNIQUE,
                status varchar(10) NOT NULL DEFAULT 'pendiente'
                    CHECK (status IN ('pendiente', 'enviada', 'fallida')),
                attempts smallint NOT NULL DEFAULT 0 CHECK (attempts <= 3),
                manual_resends smallint NOT NULL DEFAULT 0,
                last_error varchar(300) NULL,
                sent_at timestamptz NULL,
                read_at timestamptz NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                CONSTRAINT notifications_in_app_recipient_check
                    CHECK (channel <> 'in_app' OR recipient_user_id IS NOT NULL)
            );

            CREATE INDEX notifications_in_app_idx ON notifications (recipient_user_id, read_at, created_at DESC)
                WHERE channel = 'in_app';
            CREATE INDEX notifications_tenant_status_idx ON notifications (tenant_id, status, created_at);
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS notifications');
    }
};
