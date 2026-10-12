<?php

/*
 * T-163 (SDD §4.6, §6.3; RNF-045, RNF-152): Laravel descarta las respuestas del motor que no
 * cumplen el contrato 1.0 y las cuenta como fallo del Circuit Breaker.
 */

use App\Modules\Risk\Services\CircuitBreaker;
use App\Modules\Risk\Services\RiskEngineClient;
use App\Modules\Risk\Services\RiskEngineUnavailable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\MlEngine;

beforeEach(fn () => app(CircuitBreaker::class)->reset());
afterEach(fn () => app(CircuitBreaker::class)->reset());

/**
 * @param  Closure(array<string, mixed>): array<string, mixed>  $tamper
 */
function engineAnswers(Closure $tamper): ?string
{
    MlEngine::reset();
    Http::fake([MlEngine::URL => fn (Request $request) => Http::response($tamper(MlEngine::response($request->data(), 0.4)))]);

    try {
        app(RiskEngineClient::class)->predict('1.0.0', 'adulto', MlEngine::features());
    } catch (RiskEngineUnavailable $unavailable) {
        return $unavailable->outcome;
    }

    return null;
}

it('discards engine responses with another contract version', function () {
    expect(engineAnswers(fn (array $body) => [...$body, 'contract_version' => '2.0']))->toBe('respuesta_invalida')
        ->and(engineAnswers(fn (array $body) => [...$body, 'contract_version' => '1.3']))->toBeNull();
})->group('T-163', 'RNF-045', 'RNF-152');

it('discards responses with another request reference, model version or out of range probability', function () {
    expect(engineAnswers(fn (array $body) => [...$body, 'request_ref' => '7b3a0c4e-0000-4000-8000-000000000000']))->toBe('respuesta_invalida')
        ->and(engineAnswers(fn (array $body) => [...$body, 'model_version' => '9.9.9']))->toBe('respuesta_invalida')
        ->and(engineAnswers(fn (array $body) => [...$body, 'probability' => 1.2]))->toBe('respuesta_invalida');
})->group('T-163', 'RF-167');

it('discards responses without one contribution per feature sent', function () {
    $dropOne = function (array $body) {
        array_pop($body['explanation']['individual']);

        return $body;
    };

    expect(engineAnswers($dropOne))->toBe('respuesta_invalida')
        ->and(app(CircuitBreaker::class)->state())->toBe('cerrado');
})->group('T-163', 'RNF-168');

it('sends only the contract fields and a fresh random request reference', function () {
    MlEngine::predicts(0.4);
    $client = app(RiskEngineClient::class);

    $first = $client->predict('1.0.0', 'adulto', MlEngine::features());
    $second = $client->predict('1.0.0', 'adulto', MlEngine::features());

    expect($first->requestRef)->not->toBe($second->requestRef)
        ->and($first->requestRef)->toMatch('/^[0-9a-f-]{8}-[0-9a-f-]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
    Http::assertSent(fn (Request $request) => array_keys($request->data()) === ['contract_version', 'request_ref', 'model_version', 'age_group', 'features']
        && $request['contract_version'] === '1.0');
})->group('T-163', 'RF-167', 'RES-04');
