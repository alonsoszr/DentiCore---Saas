<?php

namespace App\Providers;

use App\Modules\Platform\Models\Tenant;
use App\Support\Encryption\BlindIndex;
use App\Support\Encryption\KeyRing;
use App\Support\Encryption\TenantEncryption;
use App\Support\Evidence\EvidenceSealer;
use App\Support\Files\ClamAvScanner;
use App\Support\Files\VirusScanner;
use App\Support\Http\ProblemDetailsDocumentation;
use App\Support\Tenancy\RowLevelSecurity;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Schema\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Las claves de clínica descifradas se cachean solo durante la solicitud o el job.
        $this->app->scoped(KeyRing::class);
        $this->app->scoped(BlindIndex::class);
        $this->app->scoped(TenantEncryption::class);

        $this->app->bind(EvidenceSealer::class, fn () => new EvidenceSealer((string) config('services.evidence.hmac_key')));

        $this->app->bind(VirusScanner::class, fn () => new ClamAvScanner(
            (string) config('services.clamav.host'),
            (int) config('services.clamav.port'),
            (int) config('services.clamav.timeout'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Migraciones: Schema::enableTenantRls('tabla') en cada tabla BT (SDD §2.13).
        Builder::macro('enableTenantRls', fn (string $table) => RowLevelSecurity::enable($table));
        Builder::macro('disableTenantRls', fn (string $table) => RowLevelSecurity::disable($table));

        // throttle:api: 60 solicitudes por minuto por usuario (SDD §1.7, §4.2; DD-19).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        // throttle:tenant: solicitudes por minuto por clínica según su plan (SDD §4.2; RNF-042).
        // Laravel ordena los limitadores antes que `tenant`, así que la clínica sale del usuario.
        RateLimiter::for('tenant', function (Request $request) {
            $tenantId = $request->user()?->tenant_id;
            $perMinute = $tenantId === null ? null : Tenant::query()->whereKey($tenantId)
                ->join('subscription_plans', 'subscription_plans.id', '=', 'tenants.subscription_plan_id')
                ->value('subscription_plans.rate_limit_per_minute');

            return Limit::perMinute((int) ($perMinute ?? 1200))->by('tenant:'.($tenantId ?? $request->ip()));
        });

        // throttle:codes: 5 intentos fallidos cada 15 min por usuario (o token) y propósito
        // (SDD §1.7, §4.2; RNF-111). Solo cuentan las respuestas de error.
        RateLimiter::for('codes', function (Request $request) {
            $token = $request->route('token');
            $subject = $request->user()?->id
                ? 'user:'.$request->user()->id
                : (is_string($token) ? 'token:'.hash('sha256', $token) : 'ip:'.$request->ip());

            return Limit::perMinutes(15, 5)
                ->by($subject.'|'.($request->route()?->getName() ?? $request->path()))
                ->after(fn ($response): bool => $response->getStatusCode() >= 400);
        });

        // Contrato OpenAPI 3.1 (SDD §4.1, RNF-044): errores como problem+json.
        Scramble::afterOpenApiGenerated(new ProblemDetailsDocumentation);
    }
}
