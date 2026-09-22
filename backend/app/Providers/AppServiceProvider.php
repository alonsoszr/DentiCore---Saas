<?php

namespace App\Providers;

use App\Services\Encryption\TenantEncryption;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Las claves de clínica descifradas se cachean solo durante el request.
        $this->app->scoped(TenantEncryption::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
