<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de hallazgos de la NTS N° 188 (TASK-043a; SDD §2.6 `finding_catalog` y
 * `finding_states`; RN-17, RF-077, RF-078, DD-05). Tablas de plataforma, iguales para todas las
 * clínicas. Se crean sin datos: el contenido del anexo de la norma se siembra en TASK-043b con la
 * revisión pendiente del validador clínico (PQ-05). Un hallazgo nunca se elimina: se retira con
 * `is_active` y `retired_in_version` (RF-078).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE finding_catalog (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                code varchar(20) NOT NULL UNIQUE,
                name varchar(150) NOT NULL,
                acronym varchar(10) NULL,
                level varchar(10) NOT NULL CONSTRAINT finding_catalog_level_check CHECK (level IN ('pieza', 'superficie', 'tramo')),
                dentition varchar(10) NOT NULL CONSTRAINT finding_catalog_dentition_check CHECK (dentition IN ('permanente', 'temporal', 'ambas')),
                introduced_in_version varchar(10) NOT NULL,
                retired_in_version varchar(10) NULL,
                is_active boolean NOT NULL DEFAULT true,
                display_order smallint NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL
            );
            CREATE TRIGGER trg_forbid_delete BEFORE DELETE ON finding_catalog
                FOR EACH ROW EXECUTE FUNCTION fn_forbid_update_delete();

            CREATE TABLE finding_states (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                finding_id bigint NOT NULL REFERENCES finding_catalog (id) ON DELETE RESTRICT,
                code varchar(20) NOT NULL,
                name varchar(100) NOT NULL,
                color varchar(5) NOT NULL CONSTRAINT finding_states_color_check CHECK (color IN ('azul', 'rojo')),
                acronym varchar(10) NULL,
                is_active boolean NOT NULL DEFAULT true,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL,
                UNIQUE (finding_id, code)
            );
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS finding_states; DROP TABLE IF EXISTS finding_catalog;');
    }
};
