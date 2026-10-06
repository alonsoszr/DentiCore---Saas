<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `legal_representatives` (TASK-034; SDD §2.5; RN-12, RN-13, RF-060, RF-061, DD-04, DD-13): BT
 * con documento y teléfono cifrados, índice ciego del documento y FK compuestas hacia el
 * representado y, si también es paciente, hacia el representante (DI-19).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE legal_representatives (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                uuid uuid NOT NULL UNIQUE DEFAULT gen_random_uuid(),
                tenant_id bigint NOT NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                patient_id bigint NOT NULL,
                representative_patient_id bigint NULL,
                document_type varchar(10) NOT NULL CHECK (document_type IN ('dni', 'ce', 'pasaporte', 'cpp')),
                document_number text NOT NULL,
                document_hash char(64) NOT NULL,
                first_name varchar(100) COLLATE "es-PE-x-icu" NOT NULL,
                last_name varchar(100) COLLATE "es-PE-x-icu" NOT NULL,
                relationship varchar(10) NOT NULL CHECK (relationship IN ('madre', 'padre', 'tutor', 'curador', 'otro')),
                phone text NOT NULL,
                email varchar(180) NULL,
                user_id bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
                valid_from date NOT NULL,
                valid_until date NULL,
                ended_reason varchar(20) NULL CHECK (ended_reason IN ('mayoria_de_edad', 'revocada', 'otro')),
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (tenant_id, id),
                CONSTRAINT legal_representatives_validity_check CHECK (valid_until IS NULL OR valid_until >= valid_from),
                FOREIGN KEY (tenant_id, patient_id) REFERENCES patients (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, representative_patient_id) REFERENCES patients (tenant_id, id) ON DELETE RESTRICT
            );

            CREATE INDEX legal_representatives_current_idx ON legal_representatives (tenant_id, patient_id) WHERE valid_until IS NULL;
            CREATE INDEX legal_representatives_user_idx ON legal_representatives (tenant_id, user_id);
            CREATE INDEX legal_representatives_document_idx ON legal_representatives (tenant_id, document_hash);
            SQL);

        RowLevelSecurity::enable('legal_representatives');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('legal_representatives');
        DB::statement('DROP TABLE IF EXISTS legal_representatives');
    }
};
