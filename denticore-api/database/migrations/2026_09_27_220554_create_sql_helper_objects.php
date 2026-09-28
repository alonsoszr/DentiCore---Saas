<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Objetos SQL auxiliares de SDD §2.2 (TASK-006): extensiones, validación de piezas y
 * superficies (RN-16, RN-18), mismo arco, tipo `timerange` (DI-09), inmutabilidad con la
 * excepción `app.retention_delete` (DI-21) y cadena de hashes (DI-11).
 *
 * Las funciones usan CREATE OR REPLACE y el tipo se crea solo si no existe, porque
 * `migrate:fresh` elimina tablas pero no funciones ni tipos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS btree_gist;
            CREATE EXTENSION IF NOT EXISTS pg_trgm;
            CREATE EXTENSION IF NOT EXISTS pgcrypto;

            -- Piezas del Sistema Dígito Dos (RN-16)
            CREATE OR REPLACE FUNCTION fn_valid_tooth(t smallint) RETURNS boolean IMMUTABLE LANGUAGE sql AS $$
              SELECT (t / 10 IN (1,2,3,4) AND t % 10 BETWEEN 1 AND 8)
                  OR (t / 10 IN (5,6,7,8) AND t % 10 BETWEEN 1 AND 5) $$;

            -- Superficies por tipo de pieza (RN-18): O premolares/molares; I incisivos/caninos;
            -- P superiores (cuadrantes 1,2,5,6); L inferiores (3,4,7,8); M, D, V todas.
            CREATE OR REPLACE FUNCTION fn_valid_surfaces(t smallint, s text[]) RETURNS boolean IMMUTABLE LANGUAGE sql AS $$
              SELECT s <@ ARRAY['M','D','O','I','V','L','P']
                 AND cardinality(s) = cardinality(ARRAY(SELECT DISTINCT unnest(s)))
                 AND (NOT 'O' = ANY(s) OR t % 10 >= 4)
                 AND (NOT 'I' = ANY(s) OR t % 10 <= 3)
                 AND (NOT 'P' = ANY(s) OR (t / 10) IN (1,2,5,6))
                 AND (NOT 'L' = ANY(s) OR (t / 10) IN (3,4,7,8)) $$;

            -- Mismo arco (superior: cuadrantes 1,2,5,6; inferior: 3,4,7,8) para hallazgos de tramo
            CREATE OR REPLACE FUNCTION fn_same_arch(a smallint, b smallint) RETURNS boolean IMMUTABLE LANGUAGE sql AS $$
              SELECT ((a / 10) IN (1,2,5,6)) = ((b / 10) IN (1,2,5,6)) $$;

            -- Rango de horas para franjas del horario laboral (DI-09)
            DO $$
            BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'timerange') THEN
                CREATE TYPE timerange AS RANGE (subtype = time);
              END IF;
            END $$;

            -- Inmutabilidad (RN-22, RN-34, RN-67, RN-78)
            CREATE OR REPLACE FUNCTION fn_forbid_update_delete() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF TG_OP = 'DELETE' AND current_setting('app.retention_delete', true) = 'on' THEN RETURN OLD; END IF;  -- DI-21
              RAISE EXCEPTION 'immutable_row: % on %', TG_OP, TG_TABLE_NAME USING ERRCODE = '55000';
            END $$;

            -- Cadena de hashes (DD-46, DI-11)
            -- TG_ARGV[0] = tabla padre (particionada); TG_ARGV[1] = columna que agrupa la cadena;
            -- TG_ARGV[2] = columna excluida del hash (opcional)
            CREATE OR REPLACE FUNCTION fn_hash_chain() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE scope_val bigint; prev text;
            BEGIN
              EXECUTE format('SELECT ($1).%I', TG_ARGV[1]) INTO scope_val USING NEW;
              PERFORM pg_advisory_xact_lock(hashtextextended(TG_ARGV[0] || ':' || coalesce(scope_val, 0), 0));
              EXECUTE format('SELECT hash FROM %I WHERE %I IS NOT DISTINCT FROM $1 ORDER BY id DESC LIMIT 1',
                             TG_ARGV[0], TG_ARGV[1]) INTO prev USING scope_val;
              NEW.prev_hash := coalesce(prev, repeat('0', 64));
              NEW.hash := encode(sha256(convert_to(NEW.prev_hash
                          || (to_jsonb(NEW) - 'hash' - 'prev_hash' - coalesce(TG_ARGV[2], ''))::text, 'UTF8')), 'hex');
              RETURN NEW;
            END $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS fn_hash_chain();
            DROP FUNCTION IF EXISTS fn_forbid_update_delete();
            DROP TYPE IF EXISTS timerange;
            DROP FUNCTION IF EXISTS fn_same_arch(smallint, smallint);
            DROP FUNCTION IF EXISTS fn_valid_surfaces(smallint, text[]);
            DROP FUNCTION IF EXISTS fn_valid_tooth(smallint);
            SQL);
    }
};
