<?php

/*
 * Esquema de atención y odontograma (TASK-045; SDD §2.6; RF-082, RN-16 a RN-18, RN-23, DD-46,
 * DI-17, S-10).
 */

use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\FindingState;
use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('keeps a single open attention per dentist and patient', function () {
    $attention = Attention::factory()->create();
    $same = ['tenant_id' => $attention->tenant_id, 'patient_id' => $attention->patient_id, 'dentist_id' => $attention->dentist_id];

    TenantContext::run($attention->tenant_id, fn () => expect(fn () => DB::transaction(
        fn () => DB::table('attentions')->insert([...$same, 'is_first_attention' => false, 'opened_at' => now()]),
    ))->toThrow(QueryException::class, 'attentions_open_unique'));

    // Una atención cerrada del mismo par y una abierta con otro odontólogo no chocan.
    Attention::factory()->create([...$same, 'status' => 'cerrada', 'closed_at' => now()]);
    Attention::factory()->create([...$same, 'dentist_id' => Attention::factory()->create(['tenant_id' => $attention->tenant_id])->dentist_id]);

    TenantContext::run($attention->tenant_id, fn () => expect(Attention::query()->where('patient_id', $attention->patient_id)->count())->toBe(3));
})->group('RF-082');

it('rejects entries that break the odontogram rules', function (array $values, string $constraint) {
    $entry = OdontogramEntry::factory()->make();
    $state = FindingState::query()->find($entry->finding_state_id);

    $values = array_map(fn ($value) => $value instanceof Closure ? $value($entry, $state) : $value, $values);
    $row = [...$entry->getAttributes(), ...$values];
    $row['surfaces'] = is_array($row['surfaces']) ? '{'.implode(',', $row['surfaces']).'}' : $row['surfaces'];

    // Inserción directa: el contexto de clínica envuelve la transacción que falla.
    TenantContext::run($entry->tenant_id, fn () => expect(fn () => DB::transaction(fn () => DB::table('odontogram_entries')->insert($row)))
        ->toThrow(QueryException::class, $constraint));
})->with([
    'pieza fuera del Sistema Dígito Dos' => [['tooth' => 19], 'odontogram_entries_tooth_check'],
    'tramo hacia el otro arco' => [['tooth' => 13, 'tooth_end' => 43, 'surfaces' => []], 'odontogram_entries_tooth_end_check'],
    'tramo hacia la misma pieza' => [['tooth' => 13, 'tooth_end' => 13, 'surfaces' => []], 'odontogram_entries_tooth_end_check'],
    'oclusal en un incisivo' => [['tooth' => 11, 'surfaces' => ['O']], 'odontogram_entries_surfaces_check'],
    'palatina en una pieza inferior' => [['tooth' => 36, 'surfaces' => ['P']], 'odontogram_entries_surfaces_check'],
    'color distinto del estado' => [['color' => fn ($entry, $state) => $state->color === 'rojo' ? 'azul' : 'rojo'], 'odontogram_entries_state_color_check'],
    'estado de otro hallazgo' => [['finding_state_id' => fn () => FindingState::factory()->create()->id], 'odontogram_entries_state_color_check'],
    'cadena de otro paciente' => [['chain_patient_id' => fn ($entry) => $entry->patient_id + 1], 'odontogram_entries_chain_patient_check'],
    'inicial sin odontograma inicial' => [['entry_type' => 'inicial', 'initial_odontogram_id' => null], 'odontogram_entries_initial_check'],
    'IA sin sugerencia' => [['origin' => 'ia'], 'odontogram_entries_ai_suggestion_check'],
    'procedimiento sin procedimiento realizado' => [['origin' => 'procedimiento'], 'odontogram_entries_procedure_check'],
    'evolución que corrige otra entrada' => [['corrects_entry_id' => 1], 'odontogram_entries_correction_check'],
    'corrección sin tipo' => [['entry_type' => 'correccion', 'corrects_entry_id' => 1, 'correction_reason' => 'Pieza equivocada'], 'odontogram_entries_correction_kind_check'],
    'corrección con motivo corto' => [['entry_type' => 'correccion', 'corrects_entry_id' => 1, 'correction_kind' => 'reemplazo', 'correction_reason' => 'Error'], 'odontogram_entries_correction_reason_check'],
    'anulación con hallazgo' => [['entry_type' => 'correccion', 'corrects_entry_id' => 1, 'correction_kind' => 'anulacion', 'correction_reason' => 'Pieza equivocada'], 'odontogram_entries_annulment_check'],
])->group('RN-16', 'RN-17', 'RN-18', 'RN-23', 'DD-46');

it('records an annulment without finding, state or color', function () {
    $entry = OdontogramEntry::factory()->create();

    $annulment = OdontogramEntry::factory()->create([
        'tenant_id' => $entry->tenant_id, 'attention_id' => $entry->attention_id, 'tooth' => $entry->tooth,
        'entry_type' => 'correccion', 'corrects_entry_id' => $entry->id, 'correction_kind' => 'anulacion',
        'correction_reason' => 'Registrado en la pieza equivocada', 'finding_id' => null, 'finding_state_id' => null, 'color' => null,
    ]);

    TenantContext::run($entry->tenant_id, fn () => expect(OdontogramEntry::query()->find($annulment->id))
        ->corrects_entry_id->toBe($entry->id)
        ->surfaces->toBe($entry->surfaces)
        ->hash->toHaveLength(64));
})->group('RN-23', 'CA-23.3');

it('creates the odontogram partitions for the current year and the next two with RLS', function () {
    $year = (int) now()->format('Y');

    foreach ([$year, $year + 1, $year + 2] as $partitionYear) {
        $partition = DB::selectOne(
            "select c.relrowsecurity as enabled, c.relforcerowsecurity as forced,
                    exists (select 1 from pg_policies p where p.tablename = c.relname and p.policyname = 'tenant_isolation') as has_policy
               from pg_class c where c.oid = to_regclass(?)",
            ["odontogram_entries_y{$partitionYear}"],
        );

        expect($partition)->not->toBeNull("falta la partición odontogram_entries_y{$partitionYear}")
            ->and([$partition->enabled, $partition->forced, $partition->has_policy])->toBe([true, true, true]);
    }

    $this->artisan('partitions:ensure', ['--connection' => 'pgsql_migrator'])->assertSuccessful();
})->group('DI-17', 'S-10', 'DD-40');

it('hides the entries of another clinic even when reading a partition directly', function () {
    $entry = OdontogramEntry::factory()->create();
    $partition = 'odontogram_entries_y'.$entry->recorded_at->format('Y');

    expect(DB::table($partition)->count())->toBe(0)
        ->and(TenantContext::run($entry->tenant_id, fn () => DB::table($partition)->count()))->toBe(1);
})->group('DD-40', 'RNF-102');
