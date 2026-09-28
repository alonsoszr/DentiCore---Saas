<?php

use App\Support\Audit\AuditPartitions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `audit_logs` (TASK-011; SDD §2.12, §5.14; RN-67, DD-46, DI-11, DI-17, RNF-114): bitácora
 * inalterable particionada por año de created_at, con cadena de hashes por clínica (la de
 * plataforma para tenant_id nulo) y disparador que rechaza UPDATE y DELETE.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE audit_logs (
                id bigint GENERATED ALWAYS AS IDENTITY,
                uuid uuid NOT NULL DEFAULT gen_random_uuid(),
                tenant_id bigint NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                user_id bigint NULL,
                actor_role varchar(20) NULL,
                action varchar(80) NOT NULL,
                resource_type varchar(60) NULL,
                resource_uuid uuid NULL,
                patient_uuid uuid NULL,
                changed_fields text[] NULL,
                ip_address inet NULL,
                user_agent varchar(300) NULL,
                correlation_id uuid NULL,
                metadata jsonb NULL,
                prev_hash char(64) NOT NULL,
                hash char(64) NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                PRIMARY KEY (id, created_at),
                UNIQUE (uuid, created_at)
            ) PARTITION BY RANGE (created_at);

            CREATE INDEX audit_logs_tenant_created_idx ON audit_logs (tenant_id, created_at DESC);
            CREATE INDEX audit_logs_tenant_user_idx ON audit_logs (tenant_id, user_id, created_at);
            CREATE INDEX audit_logs_tenant_resource_idx ON audit_logs (tenant_id, resource_type, resource_uuid);
            CREATE INDEX audit_logs_tenant_action_idx ON audit_logs (tenant_id, action, created_at);

            CREATE TRIGGER trg_hash_chain BEFORE INSERT ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION fn_hash_chain('audit_logs', 'tenant_id');
            CREATE TRIGGER trg_forbid_update_delete BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION fn_forbid_update_delete();
            SQL);

        AuditPartitions::ensure(DB::connection(), 'audit_logs', (int) now()->format('Y'));
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS audit_logs CASCADE');
    }
};
