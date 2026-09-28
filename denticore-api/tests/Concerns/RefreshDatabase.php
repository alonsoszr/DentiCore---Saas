<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase as BaseRefreshDatabase;

/**
 * RefreshDatabase con los roles de BD de SDD §2.13: las migraciones corren con el rol
 * propietario (`pgsql_migrator`) y las pruebas con el rol de la API (`denticore_app`,
 * conexión por defecto), igual que en producción.
 */
trait RefreshDatabase
{
    use BaseRefreshDatabase {
        migrateFreshUsing as baseMigrateFreshUsing;
    }

    /**
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return [
            ...$this->baseMigrateFreshUsing(),
            '--database' => 'pgsql_migrator',
        ];
    }
}
