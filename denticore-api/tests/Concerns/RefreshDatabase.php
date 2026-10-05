<?php

namespace Tests\Concerns;

use App\Modules\Patients\Services\PatientSearchRepository;
use Illuminate\Foundation\Testing\RefreshDatabase as BaseRefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * RefreshDatabase con los roles de BD de SDD §2.13: las migraciones corren con el rol
 * propietario (`pgsql_migrator`) y las pruebas con el rol de la API (`denticore_app`,
 * conexión por defecto), igual que en producción.
 */
trait RefreshDatabase
{
    use BaseRefreshDatabase {
        migrateFreshUsing as baseMigrateFreshUsing;
        refreshDatabase as baseRefreshDatabase;
    }

    /**
     * La búsqueda de pacientes lee los ids por la conexión de plataforma, que no ve las filas sin
     * confirmar de la transacción de la prueba; aquí los lee por la conexión de la prueba (rol de
     * la API, con RLS). PatientSearchTest cubre la conexión de plataforma con datos confirmados.
     */
    public function refreshDatabase(): void
    {
        $this->baseRefreshDatabase();

        $this->app->instance(PatientSearchRepository::class, new PatientSearchRepository(DB::connection()));
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
