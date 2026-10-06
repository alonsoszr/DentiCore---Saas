<?php

/*
 * Inmutabilidad del odontograma, la nota y los diagnósticos en la BD (TASK-045; SDD §2.6;
 * RN-20, RN-22, RN-78, CA-22.5, DI-21).
 */

use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\InitialOdontogram;
use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Modules\Patients\Models\Patient;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('rejects UPDATE and DELETE on odontogram entries at database level', function () {
    $entry = OdontogramEntry::factory()->create(['note' => 'Original']);

    TenantContext::run($entry->tenant_id, function () use ($entry) {
        $entries = fn () => DB::table('odontogram_entries')->where('id', $entry->id);

        expect(fn () => DB::transaction(fn () => $entries()->update(['note' => 'Alterada'])))
            ->toThrow(QueryException::class, 'immutable_row: UPDATE on odontogram_entries')
            ->and(fn () => DB::transaction(fn () => $entries()->update(['tooth' => 17])))
            ->toThrow(QueryException::class, 'immutable_row')
            ->and(fn () => DB::transaction(fn () => $entries()->delete()))
            ->toThrow(QueryException::class, 'immutable_row: DELETE on odontogram_entries')
            ->and(fn () => DB::transaction(fn () => $entry->forceFill(['note' => 'Alterada'])->save()))
            ->toThrow(QueryException::class, 'immutable_row')
            ->and($entries()->value('note'))->toBe('Original');
    });
})->group('T-053', 'RN-22', 'RF-089', 'CA-22.5');

it('only lets a patient merge change patient_id and a retention delete remove an entry', function () {
    $entry = OdontogramEntry::factory()->create();
    $target = Patient::factory()->create(['tenant_id' => $entry->tenant_id]);

    TenantContext::run($entry->tenant_id, function () use ($entry, $target) {
        $entries = fn () => DB::table('odontogram_entries')->where('id', $entry->id);
        $asMerge = fn (array $values) => DB::transaction(function () use ($entries, $values) {
            DB::select("select set_config('app.patient_merge', 'on', true)");
            $entries()->update($values);
        });

        // La fusión solo reasigna la ficha (DD-37); el resto de la entrada no cambia.
        expect(fn () => $asMerge(['patient_id' => $target->id, 'note' => 'Alterada']))->toThrow(QueryException::class, 'immutable_row');
        $asMerge(['patient_id' => $target->id]);
        expect($entries()->first())
            ->patient_id->toBe($target->id)
            ->chain_patient_id->toBe($entry->patient_id);

        DB::transaction(function () use ($entries) {
            DB::select("select set_config('app.retention_delete', 'on', true)");
            $entries()->delete();
        });
        expect($entries()->exists())->toBeFalse();
    });
})->group('DI-21', 'DD-37', 'RN-22');

it('locks the clinical note once signed or once the attention is no longer open', function (string $noteStatus, string $attentionStatus, bool $locked) {
    $attention = Attention::factory()->create(['status' => $attentionStatus]);

    TenantContext::run($attention->tenant_id, function () use ($attention, $noteStatus, $locked) {
        DB::table('clinical_notes')->insert([
            'tenant_id' => $attention->tenant_id, 'attention_id' => $attention->id,
            'chief_complaint' => 'Dolor al masticar', 'status' => $noteStatus,
        ]);
        $update = fn () => DB::transaction(fn () => DB::table('clinical_notes')
            ->where('attention_id', $attention->id)->update(['chief_complaint' => 'Sensibilidad al frío']));

        if ($locked) {
            expect($update)->toThrow(QueryException::class, 'immutable_row');
        } else {
            $update();
            expect(DB::table('clinical_notes')->where('attention_id', $attention->id)->value('chief_complaint'))
                ->toBe('Sensibilidad al frío');
        }
    });
})->with([
    'borrador de una atención abierta' => ['borrador', 'abierta', false],
    'firmada' => ['firmada', 'abierta', true],
    'atención cerrada' => ['borrador', 'cerrada', true],
    'atención cerrada incompleta' => ['borrador', 'cerrada_incompleta', true],
])->group('RN-78', 'RF-084');

it('only adds diagnoses from an addendum once the attention is no longer open', function () {
    $attention = Attention::factory()->create(['status' => 'abierta']);

    TenantContext::run($attention->tenant_id, function () use ($attention) {
        $diagnosis = fn (string $origin, ?int $addendumId = null) => DB::table('attention_diagnoses')->insertGetId([
            'tenant_id' => $attention->tenant_id, 'attention_id' => $attention->id, 'cie10_code' => 'K02.1',
            'type' => 'presuntivo', 'origin' => $origin, 'addendum_id' => $addendumId, 'created_by' => $attention->dentist_id,
        ]);
        $first = $diagnosis('nota');
        DB::table('attention_diagnoses')->where('id', $first)->update(['type' => 'definitivo']);

        DB::table('attentions')->where('id', $attention->id)->update(['status' => 'cerrada_incompleta', 'closed_at' => now()]);

        expect(fn () => DB::transaction(fn () => $diagnosis('nota')))->toThrow(QueryException::class, 'attention_not_open')
            ->and(fn () => DB::transaction(fn () => DB::table('attention_diagnoses')->where('id', $first)->update(['type' => 'presuntivo'])))
            ->toThrow(QueryException::class, 'immutable_row')
            ->and(fn () => DB::transaction(fn () => DB::table('attention_diagnoses')->where('id', $first)->delete()))
            ->toThrow(QueryException::class, 'immutable_row');

        $addendumId = DB::table('attention_addenda')->insertGetId([
            'tenant_id' => $attention->tenant_id, 'attention_id' => $attention->id, 'text' => 'Se completa el diagnóstico.',
            'chief_complaint' => 'Dolor al masticar', 'author_id' => $attention->dentist_id, 'author_cop' => '12345',
        ]);
        $diagnosis('adenda', $addendumId);

        expect(DB::table('attention_diagnoses')->where('attention_id', $attention->id)->pluck('origin')->sort()->values()->all())
            ->toBe(['adenda', 'nota'])
            ->and(fn () => DB::transaction(fn () => DB::table('attention_addenda')->where('id', $addendumId)->update(['text' => 'Otro texto'])))
            ->toThrow(QueryException::class, 'immutable_row: UPDATE on attention_addenda');
    });
})->group('RN-78', 'RN-77', 'RF-097');

it('rejects any change to a closed initial odontogram', function () {
    $initial = InitialOdontogram::factory()->create();

    TenantContext::run($initial->tenant_id, function () use ($initial) {
        $odontograms = fn () => DB::table('initial_odontograms')->where('id', $initial->id);
        $odontograms()->update(['status' => 'cerrado', 'closed_at' => now(), 'closed_by' => 'cierre_atencion']);

        expect(fn () => DB::transaction(fn () => $odontograms()->update(['status' => 'abierto', 'closed_at' => null, 'closed_by' => null])))
            ->toThrow(QueryException::class, 'immutable_row')
            ->and($odontograms()->value('status'))->toBe('cerrado');
    });
})->group('RN-20');
