<?php

/*
 * T-160 (SDD §1.6.3, §6.3.10; DD-03, RNF-101): todo modelo cuya tabla tiene tenant_id
 * NOT NULL usa BelongsToTenant, salvo las excepciones documentadas en SDD §1.6.3.
 */

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('requires BelongsToTenant on every model with a non nullable tenant_id outside the documented exceptions', function () {
    // Excepciones de SDD §1.6.3 (tenant nulable o autenticación previa a la clínica).
    $exceptions = [
        'User', 'PersonalAccessToken', 'OneTimeToken', 'AuditLog', 'Notification', 'OutboxMessage',
        'PerformanceAlert', 'RequestMetric', 'ExternalCallLog', 'ScheduledTaskRun', 'IdempotencyKey',
    ];
    $checked = 0;

    foreach (glob(app_path('Modules/*/Models/*.php')) as $file) {
        $class = sprintf('App\Modules\%s\Models\%s', basename(dirname($file, 2)), basename($file, '.php'));

        if (! is_subclass_of($class, Model::class) || in_array(class_basename($class), $exceptions, true)) {
            continue;
        }

        $table = (new $class)->getTable();
        $column = DB::selectOne(
            "select is_nullable from information_schema.columns where table_schema = 'public' and table_name = ? and column_name = 'tenant_id'",
            [$table],
        );

        if ($column?->is_nullable === 'NO') {
            $checked++;
            expect(in_array(BelongsToTenant::class, class_uses_recursive($class), true))
                ->toBeTrue("{$class} ({$table}) tiene tenant_id NOT NULL y no usa BelongsToTenant");
        }
    }

    expect($checked)->toBeGreaterThan(0);
})->group('DD-03', 'RNF-101');
