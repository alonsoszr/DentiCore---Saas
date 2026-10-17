<?php

use Illuminate\Support\Facades\DB;

/*
 * Integridad en la propia base de datos (RNF-192; SDD §2): toda referencia `*_id` tiene su clave
 * foránea, salvo las excepciones de diseño listadas aquí con su motivo, y las reglas de dominio
 * también viven como CHECK o disparadores además de la validación de la aplicación.
 */

/** Columnas `*_id` sin clave foránea por diseño. */
const COLUMNS_WITHOUT_FOREIGN_KEY = [
    // SDD §2 (l. 415): las tablas particionadas (DI-17) se referencian por uuid y sin FK.
    'audit_logs.user_id' => 'particionada (DI-17)',
    'odontogram_entries.chain_patient_id' => 'particionada (DI-17); agrupa la cadena de hashes aunque la ficha se fusione (DD-46)',
    'odontogram_entries.corrects_entry_id' => 'particionada (DI-17); el servicio la valida contra la misma clínica y paciente (RN-23)',
    'odontogram_entries.performed_procedure_id' => 'particionada (DI-17)',
    // Sugerencias de la IA (RN-55, CUS-30): la tabla llega en E3.
    'odontogram_entries.ai_suggestion_id' => 'tabla ai_suggestions de E3',
    'plan_items.ai_suggestion_id' => 'tabla ai_suggestions de E3',
    // Relaciones polimórficas (Sanctum, tokens de un solo uso y documentos generados).
    'personal_access_tokens.tokenable_id' => 'polimórfica (Sanctum)',
    'one_time_tokens.tokenable_id' => 'polimórfica',
    'generated_documents.documentable_id' => 'polimórfica (DI-16)',
    // Identificadores de traza, no referencias (RNF-125).
    'audit_logs.correlation_id' => 'traza',
    'external_call_logs.correlation_id' => 'traza',
    // Tabla de sesiones de Laravel; la API usa tokens (DD-19).
    'sessions.user_id' => 'tabla de Laravel sin uso en la API',
];

it('declares a foreign key for every reference column outside the design exceptions', function () {
    $missing = collect(DB::select(<<<'SQL'
        select c.table_name || '.' || c.column_name as name
          from information_schema.columns c
          join pg_class t on t.relname = c.table_name and t.relkind in ('r', 'p') and not t.relispartition
          join pg_namespace n on n.oid = t.relnamespace and n.nspname = 'public'
         where c.table_schema = 'public' and c.column_name like '%\_id' escape '\'
           and not exists (
             select 1 from pg_constraint k
               join pg_attribute a on a.attrelid = k.conrelid and a.attnum = any (k.conkey)
              where k.contype = 'f' and k.conrelid = t.oid and a.attname = c.column_name)
         order by 1
        SQL))->pluck('name')->all();

    expect($missing)->toEqualCanonicalizing(array_keys(COLUMNS_WITHOUT_FOREIGN_KEY));
})->group('RNF-192');

it('keeps domain rules as CHECK constraints and triggers too', function () {
    $checks = (int) DB::scalar("select count(*) from pg_constraint k join pg_namespace n on n.oid = k.connamespace where n.nspname = 'public' and k.contype = 'c'");
    $immutability = collect(DB::select(<<<'SQL'
        select distinct c.relname as name
          from pg_trigger g
          join pg_class c on c.oid = g.tgrelid
         where not g.tgisinternal and g.tgname = 'trg_forbid_update_delete' and not c.relispartition
        SQL))->pluck('name')->all();

    expect($checks)->toBeGreaterThan(50)
        ->and($immutability)->toContain('odontogram_entries', 'audit_logs');
})->group('RNF-192', 'RN-22');
