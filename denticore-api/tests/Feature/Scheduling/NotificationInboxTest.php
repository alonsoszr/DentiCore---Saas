<?php

/*
 * Bandeja in-app (TASK-070; SDD §4.3.8, §4.8; CUS-53; RF-158): cada usuario ve solo sus avisos,
 * con el contador de no leídos, y se conservan 90 días.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Enums\NotificationEvent;
use App\Modules\Scheduling\Models\Notification;
use App\Modules\Scheduling\Services\NotificationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

function inAppNotice(User $user, int $daysAgo = 0, array $data = ['number' => 'P-000001', 'total' => '150.00']): Notification
{
    $notice = TenantContext::run($user->tenant_id, fn () => app(NotificationService::class)->notifyInApp(
        NotificationEvent::PresupuestoAceptado, $user, $data, ['budget' => 'https://app.test/presupuestos/1'],
    ));
    DB::table('notifications')->where('id', $notice->id)->update(['created_at' => now()->subDays($daysAgo)]);

    return $notice->fresh();
}

it('lists only the notifications of the user with the unread count', function () {
    $tenant = Tenant::factory()->create();
    $user = $this->actingAsRole('receptionist', $tenant);
    $older = inAppNotice($user, 2);
    $newer = inAppNotice($user);
    inAppNotice(User::factory()->for($tenant)->create(['role' => 'receptionist']));

    $this->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $newer->uuid)
        ->assertJsonPath('data.1.id', $older->uuid)
        ->assertJsonPath('data.0.event', 'presupuesto_aceptado')
        ->assertJsonPath('data.0.params.number', 'P-000001')
        ->assertJsonPath('data.0.read_at', null)
        ->assertJsonPath('meta.unread_count', 2);
})->group('RF-158', 'CUS-53');

it('marks one or all notifications as read and answers 404 for another user notification', function () {
    $tenant = Tenant::factory()->create();
    $user = $this->actingAsRole('dentist', $tenant);
    $first = inAppNotice($user);
    inAppNotice($user);
    $foreign = inAppNotice(User::factory()->for($tenant)->create(['role' => 'dentist']));

    $this->postJson("/api/v1/notifications/{$foreign->uuid}/read")->assertNotFound();
    $this->postJson("/api/v1/notifications/{$first->uuid}/read")->assertOk()->assertJsonPath('data.id', $first->uuid);
    $this->getJson('/api/v1/notifications')->assertJsonPath('meta.unread_count', 1);

    $this->postJson('/api/v1/notifications/read-all')->assertNoContent();
    $this->getJson('/api/v1/notifications')->assertJsonPath('meta.unread_count', 0);
    expect($foreign->fresh()->read_at)->toBeNull();
})->group('RF-158', 'CUS-53', 'RNF-101');

it('keeps notifications for 90 days', function () {
    $tenant = Tenant::factory()->create();
    $user = $this->actingAsRole('clinic_admin', $tenant);
    $recent = inAppNotice($user, 89);
    $old = inAppNotice($user, 91);

    $this->getJson('/api/v1/notifications')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $recent->uuid);

    $this->artisan('notifications:prune-inapp')->assertSuccessful();
    expect(Notification::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Notification::query()->whereKey($recent->id)->exists())->toBeTrue();
})->group('RF-158');
