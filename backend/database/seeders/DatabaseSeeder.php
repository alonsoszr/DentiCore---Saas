<?php

namespace Database\Seeders;

use App\Models\Patient;
use App\Models\User;
use App\Services\TenantService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Datos de demostración para desarrollo local (no es parte del producto): una cuenta
 * super_admin y una clínica con una cuenta por rol, todas con contraseña "password".
 * Necesario porque el SDD no define cómo se crea el primer clinic_admin de una clínica.
 */
class DatabaseSeeder extends Seeder
{
    public function run(TenantService $tenants): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Los datos de demostración solo se cargan en entorno local.');
        }

        User::factory()->superAdmin()->create([
            'name' => 'Admin Plataforma',
            'email' => 'admin@denticore.test',
        ]);

        $clinic = $tenants->create([
            'name' => 'Clínica Demo',
            'slug' => 'clinica-demo',
            'subscription_plan' => 'pro',
        ]);

        $accounts = [
            'clinic_admin' => ['Carla Administradora', 'admin@clinica-demo.test'],
            'dentist' => ['Diego Odontólogo', 'dentista@clinica-demo.test'],
            'receptionist' => ['Rosa Recepcionista', 'recepcion@clinica-demo.test'],
            'patient' => ['Pablo Paciente', 'paciente@clinica-demo.test'],
        ];

        foreach ($accounts as $role => [$name, $email]) {
            User::factory()->for($clinic)->create(['name' => $name, 'email' => $email, 'role' => $role]);
        }

        Patient::factory()->for($clinic)->count(20)->create();
    }
}
