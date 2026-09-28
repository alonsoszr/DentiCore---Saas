<?php

/*
 * Evidencias, reloj de clínica e integridad (TASK-014; SDD §1.7, §1.9; DD-46, RF-009,
 * RNF-089, RNF-113, RNF-114).
 */

use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Evidence\EvidenceSealer;
use App\Support\Evidence\HashChainVerifier;
use App\Support\Time\ClinicClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('detects any change to a sealed payload', function () {
    $sealer = app(EvidenceSealer::class);
    $payload = [
        'patient' => 'b3c1…',
        'purposes' => ['atencion' => true, 'notificaciones' => false],
        'channel' => 'presencial',
        'granted_at' => '2026-10-05T14:00:00-05:00',
    ];

    $seal = $sealer->seal($payload);

    expect($seal)->toMatch('/^[0-9a-f]{64}$/')
        ->and($sealer->verify($payload, $seal))->toBeTrue()
        // El orden de las claves no cambia el sello (JSON canónico).
        ->and($sealer->verify(array_reverse($payload, true), $seal))->toBeTrue()
        ->and($sealer->verify([...$payload, 'channel' => 'portal'], $seal))->toBeFalse()
        ->and($sealer->verify(['purposes' => ['atencion' => true, 'notificaciones' => true]] + $payload, $seal))->toBeFalse();
})->group('DD-46', 'RNF-113');

it('does not seal without the evidence key', function () {
    expect(fn () => (new EvidenceSealer(''))->seal(['a' => 1]))->toThrow(RuntimeException::class);
})->group('DD-46');

it('computes the end of the local day of America/Lima in UTC', function () {
    $clock = ClinicClock::for(Tenant::factory()->make());

    expect($clock->timezone())->toBe('America/Lima')
        ->and($clock->endOfLocalDay('2026-10-05')->toIso8601ZuluString())->toBe('2026-10-06T04:59:59Z')
        ->and($clock->startOfLocalDay('2026-10-05')->toIso8601ZuluString())->toBe('2026-10-05T05:00:00Z')
        ->and($clock->localDate(CarbonImmutable::parse('2026-10-06T03:30:00Z')))->toBe('2026-10-05');

    $this->travelTo('2026-10-06T04:30:00Z');
    expect(ClinicClock::inTimezone('America/Lima')->now()->format('Y-m-d H:i'))->toBe('2026-10-05 23:30');
})->group('RF-009', 'RNF-130');

it('reports an intact audit chain', function () {
    app(AuditLogger::class)->record(AuditEvent::TenantCreated, Tenant::factory()->create());
    app(AuditLogger::class)->record(AuditEvent::TenantCreated, Tenant::factory()->create());

    expect(app(HashChainVerifier::class)->brokenRows(DB::connection(), 'audit_logs', 'tenant_id'))->toBe([]);
})->group('RNF-089', 'RNF-114');

it('detects an altered audit row in the daily verification run with the platform role', function () {
    // Datos confirmados (fuera de la transacción de la prueba) para que la conexión de
    // plataforma los vea; la alteración la hace el propietario del esquema desactivando el
    // disparador de inmutabilidad, como lo haría un acceso indebido a la BD.
    $owner = DB::connection('pgsql_migrator');
    $marker = (string) Str::uuid();
    $insert = fn (string $action) => $owner->table('audit_logs')->insert([
        'action' => $action, 'resource_uuid' => $marker, 'actor_role' => 'system', 'prev_hash' => '', 'hash' => '',
    ]);

    try {
        $insert('tenant.created');
        $insert('tenant.suspended');

        $this->artisan('integrity:verify', ['--connection' => 'pgsql_platform'])->assertSuccessful();

        $owner->statement('ALTER TABLE audit_logs DISABLE TRIGGER trg_forbid_update_delete');
        $owner->update("UPDATE audit_logs SET action = 'tenant.reactivated' WHERE resource_uuid = ? AND action = 'tenant.created'", [$marker]);
        $owner->statement('ALTER TABLE audit_logs ENABLE TRIGGER trg_forbid_update_delete');

        $alteredId = $owner->selectOne("SELECT id FROM audit_logs WHERE resource_uuid = ? AND action = 'tenant.reactivated'", [$marker])->id;

        expect(app(HashChainVerifier::class)->brokenRows(DB::connection('pgsql_platform'), 'audit_logs', 'tenant_id'))->toBe([$alteredId]);
        $this->artisan('integrity:verify', ['--connection' => 'pgsql_platform'])->assertFailed();
    } finally {
        $owner->statement('ALTER TABLE audit_logs ENABLE TRIGGER trg_forbid_update_delete');
        $owner->transaction(function () use ($owner, $marker) {
            $owner->select("SELECT set_config('app.retention_delete', 'on', true)");
            $owner->delete('DELETE FROM audit_logs WHERE resource_uuid = ?', [$marker]);
        });
    }
})->group('RNF-089', 'RNF-114', 'DD-46');
