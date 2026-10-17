<?php

/*
 * Aislamiento de las rutas, jobs, PDF y búsquedas de MS-01 a MS-05 (TASK-042, TASK-063, TASK-073; SDD §1.6,
 * §6.3; RN-01, RN-03, RF-003, RNF-101). T-015 y T-016.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Jobs\AutoCloseAttentionsJob;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Modules\Patients\Jobs\EndRepresentationsAtMajorityJob;
use App\Modules\Patients\Models\Consent;
use App\Modules\Patients\Models\InformedConsent;
use App\Modules\Patients\Models\LegalRepresentative;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentService;
use App\Modules\Patients\Services\InformedConsentService;
use App\Modules\Patients\Services\InformedConsentTemplateService;
use App\Modules\Patients\Services\LegalRepresentativeService;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Risk\Jobs\ExpirePredictionsJob;
use App\Modules\Risk\Models\RiskAlert;
use App\Modules\Risk\Models\RiskPrediction;
use App\Modules\Risk\Models\RiskVariableSet;
use App\Modules\Scheduling\Enums\NotificationEvent;
use App\Modules\Scheduling\Jobs\MarkNoShowsJob;
use App\Modules\Scheduling\Jobs\SendNotificationJob;
use App\Modules\Scheduling\Jobs\SendRemindersJob;
use App\Modules\Scheduling\Mail\NotificationMail;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentType;
use App\Modules\Scheduling\Models\Notification;
use App\Modules\Scheduling\Models\ScheduleBlock;
use App\Modules\Scheduling\Services\NotificationService;
use App\Modules\Treatment\Jobs\ExpireBudgetsJob;
use App\Modules\Treatment\Models\Budget;
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Models\Procedure;
use App\Modules\Treatment\Models\TreatmentPlan;
use App\Modules\Treatment\Services\BudgetIssuer;
use App\Modules\Treatment\Services\BudgetService;
use App\Support\Outbox\OutboxMessage;
use App\Support\Tenancy\TenantContext;
use App\Support\Tokens\OneTimeTokenService;
use App\Support\Tokens\TokenPurpose;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ficha de un menor con su representante y su consentimiento en la clínica indicada.
 *
 * @return array{patient: Patient, representative: LegalRepresentative, consent: Consent}
 */
function foreignClinicRecord(Tenant $tenant, string $birthDate = '2015-01-01'): array
{
    return TenantContext::run($tenant, function () use ($tenant, $birthDate) {
        $patient = Patient::factory()->for($tenant)->create(['birth_date' => $birthDate, 'last_name' => 'Núñez']);
        $representative = app(LegalRepresentativeService::class)->add($patient, [
            'document_type' => 'dni', 'document_number' => '41234567', 'first_name' => 'Rosa', 'last_name' => 'Mamani',
            'relationship' => 'madre', 'phone' => '912345678', 'email' => null, 'valid_from' => '2020-01-01',
        ]);
        $assistant = User::factory()->for($tenant)->create(['role' => 'receptionist']);
        $consent = app(ConsentService::class)->grant(
            $patient,
            ['channel' => 'presencial', 'confirmation_document_number' => '41234567'],
            $assistant,
            '127.0.0.1',
        );

        return ['patient' => $patient, 'representative' => $representative, 'consent' => $consent];
    });
}

/**
 * Registros comerciales de MS-03 en la clínica indicada: procedimiento, plan propuesto con su ítem,
 * presupuesto emitido (con su línea y su PDF pedido), plantilla de consentimiento informado y un
 * consentimiento firmado del ítem.
 *
 * @return array{procedure: Procedure, plan: TreatmentPlan, item: PlanItem, budget: Budget, template: string, informedConsent: InformedConsent}
 */
function foreignClinicTreatment(Tenant $tenant): array
{
    return TenantContext::run($tenant, function () use ($tenant): array {
        $patient = Patient::factory()->for($tenant)->create(['document_number' => '45678912', 'birth_date' => '1990-01-31']);
        $dentist = User::factory()->for($tenant)->create(['role' => 'dentist', 'cop_number' => '12345']);
        $procedure = Procedure::factory()->create(['tenant_id' => $tenant->id, 'requires_informed_consent' => true]);
        $plan = TreatmentPlan::factory()->create(['tenant_id' => $tenant->id, 'patient_id' => $patient->id, 'created_by' => $dentist->id, 'status' => 'propuesto']);
        $item = PlanItem::factory()->create(['tenant_id' => $tenant->id, 'treatment_plan_id' => $plan->id, 'procedure_id' => $procedure->id]);
        $budget = app(BudgetIssuer::class)->issue(app(BudgetService::class)->createDraft($plan, null), $dentist);
        $template = app(InformedConsentTemplateService::class)->create([
            'title' => 'Consentimiento de prueba', 'body' => 'El paciente {{paciente}} autoriza {{procedimiento}}.', 'procedures' => [$procedure->uuid],
        ], User::factory()->for($tenant)->create(['role' => 'clinic_admin']));
        $informedConsent = app(InformedConsentService::class)->sign($item, [
            'channel' => 'dispositivo', 'confirmation_document_number' => '45678912',
        ], $dentist, '127.0.0.1');

        return compact('procedure', 'plan', 'item', 'budget', 'informedConsent') + ['template' => $template->uuid];
    });
}

beforeEach(fn () => Storage::fake('s3'));

it('returns 404 for every route when the uuid belongs to another clinic', function () {
    // El recorrido hace más de 60 solicitudes por usuario (throttle:api); el límite real se prueba aparte.
    config(['auth.api_requests_per_minute' => 1000]);
    [$own, $other] = Tenant::factory()->count(2)->create();
    $this->actingAsRole('clinic_admin', $own);
    $foreign = foreignClinicRecord($other);
    // Registros clínicos de la otra clínica: atención, hallazgo y diagnóstico (MS-02).
    [$foreignEntry, $foreignDiagnosis] = TenantContext::run($other, function () use ($other) {
        $entry = OdontogramEntry::factory()->create(['tenant_id' => $other->id]);

        return [$entry, $entry->attention->diagnoses()->forceCreate([
            'cie10_code' => 'K02.1', 'type' => 'definitivo', 'origin' => 'nota', 'created_by' => $entry->author_id,
        ])];
    });
    // Registros comerciales de la otra clínica (MS-03).
    $treatment = foreignClinicTreatment($other);
    [$foreignLine, $foreignDocument] = TenantContext::run($other, fn () => [
        $treatment['budget']->lines()->sole()->uuid,
        $treatment['budget']->pdfDocument->uuid,
    ]);
    $bindings = [
        '{procedure}' => $treatment['procedure']->uuid,
        '{plan}' => $treatment['plan']->uuid,
        '{item}' => $treatment['item']->uuid,
        '{budget}' => $treatment['budget']->uuid,
        '{line}' => $foreignLine,
        '{document}' => $foreignDocument,
        '{template}' => $treatment['template'],
        '{informedConsent}' => $treatment['informedConsent']->uuid,
        '{patient}' => $foreign['patient']->uuid,
        '{representative}' => $foreign['representative']->uuid,
        '{consent}' => $foreign['consent']->uuid,
        '{user}' => User::factory()->for($other)->create(['role' => 'receptionist'])->uuid,
        '{attention}' => $foreignEntry->attention->uuid,
        '{entry}' => $foreignEntry->uuid,
        '{diagnosis}' => $foreignDiagnosis->uuid,
        '{tooth}' => '16',
        // Agenda y bandeja (MS-04): odontólogo, bloqueo, tipo y cita de la otra clínica, y un aviso de
        // otro usuario.
        '{dentist}' => User::factory()->for($other)->create(['role' => 'dentist'])->uuid,
        '{block}' => ScheduleBlock::factory()->create(['tenant_id' => $other->id])->uuid,
        '{type}' => AppointmentType::factory()->create(['tenant_id' => $other->id])->uuid,
        '{appointment}' => Appointment::factory()->create(['tenant_id' => $other->id])->uuid,
        // Riesgo de caries (MS-05): registro de variables, predicción y alerta de la otra clínica.
        '{set}' => RiskVariableSet::factory()->create(['tenant_id' => $other->id])->uuid,
        '{prediction}' => RiskPrediction::factory()->create(['tenant_id' => $other->id])->uuid,
        '{alert}' => RiskAlert::factory()->create(['tenant_id' => $other->id])->uuid,
        '{notification}' => TenantContext::run($other, fn () => app(NotificationService::class)->notifyInApp(
            NotificationEvent::PresupuestoAceptado, User::factory()->for($other)->create(['role' => 'receptionist']), ['number' => 'P-000001'],
        ))->uuid,
    ];

    // Toda ruta de la clínica que recibe el uuid de un registro (RF-003), tomada de las rutas
    // registradas (TASK-093). No son registros la pieza dentaria, el código de la clínica de las
    // rutas públicas ni los tokens de un solo uso; las rutas de plataforma ({tenant}, solo SA) y los
    // archivos firmados ({tenant}/{file}, DI-16) tienen sus propias pruebas.
    $notRecords = ['tooth', 'slug', 'token'];
    $records = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'))
        ->reject(fn ($route) => array_intersect($route->parameterNames(), ['tenant', 'file']) !== [])
        ->filter(fn ($route) => array_diff($route->parameterNames(), $notRecords) !== []);

    // Un parámetro nuevo sin registro de la otra clínica haría pasar la ruta sin probarla.
    $unbound = $records->flatMap(fn ($route) => array_diff($route->parameterNames(), $notRecords))
        ->unique()
        ->reject(fn (string $name) => array_key_exists('{'.$name.'}', $bindings))
        ->values()
        ->all();
    expect($unbound)->toBe([], 'Parámetros de ruta sin registro de la otra clínica en el dataset de T-015');

    $routes = $records
        ->flatMap(fn ($route) => collect($route->methods())->reject(fn ($method) => $method === 'HEAD')
            ->map(fn ($method) => [$method, $route->uri()]))
        ->values();

    expect($routes->count())->toBeGreaterThanOrEqual(86);

    foreach ($routes as [$method, $uri]) {
        $path = '/'.strtr($uri, $bindings);
        $status = $this->json($method, $path, [], ['Idempotency-Key' => (string) Str::uuid()])->status();

        expect($status)->toBe(404, "{$method} {$path} respondió {$status}");
    }
})->group('T-015', 'RN-03', 'RF-003', 'RNF-101');

it('searches and looks up patients only within their clinic', function () {
    [$own, $other] = Tenant::factory()->count(2)->create();
    $this->actingAsRole('receptionist', $own);
    $mine = TenantContext::run($own, fn () => Patient::factory()->for($own)->create(['last_name' => 'Núñez']));
    $foreign = TenantContext::run($other, fn () => Patient::factory()->for($other)->create([
        'last_name' => 'Núñez', 'document_number' => '87654321',
    ]));

    expect($this->getJson('/api/v1/patients?q=nunez')->assertOk()->json('data.*.id'))->toBe([$mine->uuid]);
    $this->getJson('/api/v1/patients/lookup?document_type=dni&document_number=87654321')->assertNotFound();
    expect($foreign->tenant_id)->toBe($other->id);
})->group('T-016', 'RN-01', 'RF-054');

it('generates the consent certificate PDF inside the clinic of the consent', function () {
    $other = Tenant::factory()->create();
    $foreign = foreignClinicRecord($other);

    $this->artisan('outbox:dispatch', ['--once' => true])->assertSuccessful();

    TenantContext::run($other, function () use ($foreign, $other) {
        $file = $foreign['consent']->fresh()->certificate->storedFile;

        expect($file->tenant_id)->toBe($other->id)
            ->and($file->path)->toStartWith("tenants/{$other->uuid}/")
            ->and(Storage::disk('s3')->get($file->path))->toStartWith('%PDF');
    });
})->group('T-016', 'RF-066', 'RNF-101');

it('ends representations at majority only for the clinic of the job', function () {
    // Menores que cumplen 18 años mañana; el job corre ese día.
    Carbon::setTestNow(Carbon::parse('2026-10-06 08:00', 'America/Lima'));
    [$own, $other] = Tenant::factory()->count(2)->create();
    $mine = foreignClinicRecord($own, '2008-10-07');
    $theirs = foreignClinicRecord($other, '2008-10-07');
    Carbon::setTestNow(Carbon::parse('2026-10-07 08:00', 'America/Lima'));

    dispatch_sync(new EndRepresentationsAtMajorityJob($own->id));

    expect(TenantContext::run($own, fn () => $mine['representative']->fresh()->ended_reason))->toBe('mayoria_de_edad')
        ->and(TenantContext::run($other, fn () => $theirs['representative']->fresh()->valid_until))->toBeNull();
})->group('T-016', 'RN-13', 'RNF-101');

it('generates the budget PDF inside the clinic of the budget', function () {
    $other = Tenant::factory()->create();
    $budget = foreignClinicTreatment($other)['budget'];

    $this->artisan('outbox:dispatch', ['--once' => true])->assertSuccessful();

    TenantContext::run($other, function () use ($budget, $other) {
        $document = $budget->fresh()->pdfDocument;
        $file = $document->storedFile;

        expect($document->status)->toBe('listo')
            ->and($file->tenant_id)->toBe($other->id)
            ->and($file->path)->toStartWith("tenants/{$other->uuid}/")
            ->and(Storage::disk('s3')->get($file->path))->toStartWith('%PDF');
    });
})->group('T-016', 'RF-118', 'RNF-101');

it('expires budgets only for the clinic of the job', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-06 10:00', 'America/Lima'));
    [$own, $other] = Tenant::factory()->count(2)->create();
    $mine = foreignClinicTreatment($own)['budget'];
    $theirs = foreignClinicTreatment($other)['budget'];
    Carbon::setTestNow(Carbon::parse('2026-11-06 09:00', 'America/Lima'));

    dispatch_sync(new ExpireBudgetsJob($own->id));

    expect(TenantContext::run($own, fn () => $mine->fresh()->status))->toBe('vencido')
        ->and(TenantContext::run($other, fn () => $theirs->fresh()->status))->toBe('emitido');
})->group('T-016', 'RF-124', 'RNF-101');

it('confirms by link only the appointment of the clinic of the token', function () {
    [$own, $other] = Tenant::factory()->count(2)->create();
    $mine = Appointment::factory()->create(['tenant_id' => $own->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addMinutes(30)]);
    $theirs = Appointment::factory()->create(['tenant_id' => $other->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addMinutes(30)]);
    $token = TenantContext::run($other, fn () => app(OneTimeTokenService::class)->issue($theirs, TokenPurpose::AppointmentConfirmation, $theirs->starts_at)['token']);
    // Un token de otro propósito no confirma ninguna cita.
    $reset = TenantContext::run($own, fn () => app(OneTimeTokenService::class)->issue(User::factory()->for($own)->create(), TokenPurpose::PasswordReset, now()->addHour())['token']);

    $this->postJson("/api/v1/public/appointment-confirmations/{$reset}")->assertNotFound();
    $this->postJson("/api/v1/public/appointment-confirmations/{$token}")->assertOk();

    expect(TenantContext::run($other, fn () => $theirs->fresh()->status))->toBe('confirmada')
        ->and(TenantContext::run($own, fn () => $mine->fresh()->status))->toBe('programada');
})->group('T-015', 'RF-150', 'RNF-101');

it('expires risk predictions only for the clinic of the job', function () {
    [$own, $other] = Tenant::factory()->count(2)->create();
    $old = ['predicted_at' => now()->subMonths(13)];
    $mine = RiskPrediction::factory()->create(['tenant_id' => $own->id, ...$old]);
    $theirs = RiskPrediction::factory()->create(['tenant_id' => $other->id, ...$old]);

    dispatch_sync(new ExpirePredictionsJob($own->id));

    expect(TenantContext::run($own, fn () => $mine->fresh()->status))->toBe('vencida')
        ->and(TenantContext::run($other, fn () => $theirs->fresh()->status))->toBe('vigente');
})->group('T-016', 'RN-60', 'RNF-101');

it('marks no-shows only for the clinic of the job', function () {
    [$own, $other] = Tenant::factory()->count(2)->create();
    $past = ['starts_at' => now()->subHours(2), 'ends_at' => now()->subHours(2)->addMinutes(30)];
    $mine = Appointment::factory()->create(['tenant_id' => $own->id, ...$past]);
    $theirs = Appointment::factory()->create(['tenant_id' => $other->id, ...$past]);

    dispatch_sync(new MarkNoShowsJob($own->id));

    expect(TenantContext::run($own, fn () => $mine->fresh()->status))->toBe('inasistencia')
        ->and(TenantContext::run($other, fn () => $theirs->fresh()->status))->toBe('programada');
})->group('T-016', 'RN-51', 'RNF-101');

it('sends reminders only for the clinic of the job', function () {
    [$own, $other] = Tenant::factory()->count(2)->create();
    $tomorrow = ['starts_at' => now()->addHours(23)->addMinutes(45), 'ends_at' => now()->addHours(24)->addMinutes(15)];
    $patient = fn (Tenant $tenant) => TenantContext::run($tenant, function () use ($tenant) {
        $patient = Patient::factory()->for($tenant)->create(['birth_date' => '1990-01-31', 'email' => Str::random(8).'@example.test']);
        app(ConsentService::class)->grant($patient, [
            'channel' => 'presencial', 'confirmation_document_number' => $patient->document_number, 'purpose_notifications' => true,
        ], User::factory()->for($tenant)->create(['role' => 'receptionist']), '127.0.0.1');

        return $patient;
    });
    Appointment::factory()->create(['tenant_id' => $own->id, 'patient_id' => $patient($own)->id, ...$tomorrow]);
    Appointment::factory()->create(['tenant_id' => $other->id, 'patient_id' => $patient($other)->id, ...$tomorrow]);

    dispatch_sync(new SendRemindersJob($own->id));

    expect(Notification::query()->where('event', 'cita_recordatorio')->pluck('tenant_id')->all())->toBe([$own->id]);
})->group('T-016', 'RN-52', 'RNF-101');

it('auto closes the open attentions only of the clinic of the job', function () {
    [$own, $other] = Tenant::factory()->count(2)->create();
    $yesterday = ['status' => 'abierta', 'opened_at' => now()->subDay()];
    $mine = Attention::factory()->create(['tenant_id' => $own->id, ...$yesterday]);
    $theirs = Attention::factory()->create(['tenant_id' => $other->id, ...$yesterday]);

    dispatch_sync(new AutoCloseAttentionsJob($own->id));

    // Sin nota, el cierre automático la deja `cerrada_incompleta` (RN-77); la otra sigue abierta.
    expect(TenantContext::run($own, fn () => $mine->fresh()->status))->toBe('cerrada_incompleta')
        ->and(TenantContext::run($other, fn () => $theirs->fresh()->status))->toBe('abierta');
})->group('T-016', 'RN-20', 'RNF-101');

it('sends a queued notification only inside the clinic of its outbox message', function () {
    Mail::fake();
    [$own, $other] = Tenant::factory()->count(2)->create();
    $notify = fn (Tenant $tenant) => TenantContext::run($tenant, fn () => app(NotificationService::class)->sendEmail(
        NotificationEvent::InvitacionActivacion,
        User::factory()->for($tenant)->create(['email' => Str::random(8).'@example.test']),
        links: ['activate' => 'https://app.test/activar'],
    ));
    $theirs = $notify($other);
    $message = OutboxMessage::query()->where('payload->notification', $theirs->uuid)->sole();

    // Un job creado para esta clínica no procesa el mensaje de la otra.
    TenantContext::run($own, fn () => (new SendNotificationJob($message->uuid, $own->id))->handle());

    Mail::assertNothingSent();
    expect(TenantContext::run($other, fn () => $theirs->fresh()->status))->toBe('pendiente')
        ->and($message->fresh()->consumed_at)->toBeNull();

    // Con su propia clínica, el mismo mensaje sí se envía.
    TenantContext::run($other, fn () => (new SendNotificationJob($message->uuid, $other->id))->handle());
    Mail::assertSent(NotificationMail::class);
})->group('T-016', 'RF-155', 'RNF-101');
