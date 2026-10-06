<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Catálogo CIE-10 (TASK-044; SDD §2.6 `cie10_codes`; DD-30, RF-085). Tabla de plataforma
 * publicada con el software, igual para todas las clínicas. La semilla es el subconjunto
 * provisional K00–K14 de `database/data/cie10_k00_k14.php` (DE-06, PL-03).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE cie10_codes (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                code varchar(7) NOT NULL UNIQUE,
                description varchar(300) NOT NULL,
                chapter varchar(3) NOT NULL,
                is_dental boolean NOT NULL,
                search_text varchar(320) NOT NULL,
                is_active boolean NOT NULL DEFAULT true,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NULL
            );
            CREATE INDEX cie10_codes_search_trgm_idx ON cie10_codes USING gin (search_text gin_trgm_ops);
            CREATE INDEX cie10_codes_dental_code_idx ON cie10_codes (is_dental DESC, code);
            SQL);

        $data = require database_path('data/cie10_k00_k14.php');

        DB::table('cie10_codes')->insert(array_map(fn (string $code, string $description) => [
            'code' => $code,
            'description' => $description,
            'chapter' => $data['chapter'],
            // DD-30: el capítulo odontológico K00–K14 se prioriza en la búsqueda.
            'is_dental' => preg_match('/^K(0\d|1[0-4])/', $code) === 1,
            'search_text' => Str::of(Str::ascii($description))->lower()->squish()->toString(),
        ], array_keys($data['codes']), $data['codes']));
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS cie10_codes');
    }
};
