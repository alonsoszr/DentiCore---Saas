<?php

/*
 * `risk:register-model` (TASK-080; SDD §5.8.4; RN-84, RF-175, supuesto S-09): registra los
 * metadatos que publica el motor y activa una sola versión con AUC ≥ 0,75.
 */

use App\Modules\Risk\Models\ModelVersion;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\Http;
use Tests\Support\MlEngine;

it('registers the metadata published by the engine and activates the version', function () {
    $previous = ModelVersion::factory()->active()->create(['version' => '0.9.0']);
    MlEngine::publishes('1.0.0');

    $this->artisan('risk:register-model', ['version' => '1.0.0', '--activate' => true])->assertSuccessful();

    $model = ModelVersion::query()->where('version', '1.0.0')->sole();
    expect($model->status)->toBe('activa')
        ->and($model->auc_validation)->toBe('0.7812')
        ->and($model->brier_score)->toBe('0.1601')
        ->and($model->dataset_hash)->toBe(str_repeat('a', 64))
        ->and($model->threshold_high)->toBe('0.600')
        ->and($previous->fresh()->status)->toBe('retirada')
        ->and(AuditLog::query()->whereIn('action', ['model.registered', 'model.activated'])->count())->toBe(2);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/models') && $request->hasHeader('X-ML-Service-Key'));
})->group('RN-84', 'RF-175');

it('rejects a version that the engine does not publish', function () {
    MlEngine::publishes('1.0.0');

    $this->artisan('risk:register-model', ['version' => '2.0.0', '--activate' => true])->assertFailed();

    expect(ModelVersion::query()->count())->toBe(0);
})->group('RN-84');

it('refuses to activate a version with AUC 0.74 and keeps the active one', function () {
    $active = ModelVersion::factory()->active()->create(['version' => '0.9.0']);
    MlEngine::publishes('1.1.0', ['auc_validation' => 0.74]);

    $this->artisan('risk:register-model', ['version' => '1.1.0', '--activate' => true])->assertFailed();

    expect(ModelVersion::query()->where('version', '1.1.0')->value('status'))->toBe('registrada')
        ->and($active->fresh()->status)->toBe('activa');
})->group('RN-84', 'RF-175');
