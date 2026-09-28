<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `stored_files` y `generated_documents` (TASK-016; SDD §2.5, §2.12, DI-16, DI-19, DD-18,
 * RNF-103): tablas BT con UNIQUE(tenant_id, id), FK compuestas dentro de la clínica y RLS.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE stored_files (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                disk varchar(20) NOT NULL,
                path varchar(300) NOT NULL,
                original_name varchar(200) NOT NULL,
                mime_type varchar(80) NOT NULL,
                size_bytes integer NOT NULL CHECK (size_bytes >= 0 AND size_bytes <= 10485760),
                sha256 char(64) NOT NULL,
                scan_status varchar(20) NOT NULL DEFAULT 'pendiente'
                    CHECK (scan_status IN ('pendiente', 'limpio', 'infectado')),
                uploaded_by bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id)
            );
            CREATE INDEX stored_files_tenant_created_idx ON stored_files (tenant_id, created_at);

            CREATE TABLE generated_documents (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                documentable_type varchar(60) NOT NULL,
                documentable_id bigint NOT NULL,
                kind varchar(40) NOT NULL CHECK (kind IN (
                    'presupuesto', 'recibo', 'constancia_consentimiento', 'consentimiento_informado',
                    'ficha_atencion', 'copia_hc', 'portabilidad_json', 'exportacion_clinica',
                    'bitacora_csv', 'reporte', 'respuesta_arco', 'importacion_rechazos')),
                status varchar(20) NOT NULL DEFAULT 'pendiente'
                    CHECK (status IN ('pendiente', 'generando', 'listo', 'fallido')),
                attempts smallint NOT NULL DEFAULT 0,
                stored_file_id bigint NULL,
                requested_by bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
                completed_at timestamptz NULL,
                error varchar(300) NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id),
                FOREIGN KEY (tenant_id, stored_file_id) REFERENCES stored_files (tenant_id, id) ON DELETE RESTRICT
            );
            CREATE INDEX generated_documents_documentable_idx
                ON generated_documents (tenant_id, documentable_type, documentable_id, kind);
            SQL);

        RowLevelSecurity::enable('stored_files');
        RowLevelSecurity::enable('generated_documents');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS generated_documents');
        DB::statement('DROP TABLE IF EXISTS stored_files');
    }
};
