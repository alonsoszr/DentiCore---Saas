<?php

namespace Database\Seeders;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Services\TenantService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Datos de demostración para desarrollo local (no es parte del producto): una cuenta
 * super_admin y una clínica con una cuenta por rol, todas con contraseña "password".
 * La cuenta patient queda vinculada a su propia ficha para probar el portal.
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
            'legal_name' => 'Clínica Demo S.A.C.',
            'ruc' => '20600000013',
            'slug' => 'clinica-demo',
            'address' => 'Av. Arequipa 1234, Lima',
            'subscription_plan' => 'pro',
            'admin' => ['name' => 'Carla Administradora', 'email' => 'admin@clinica-demo.test'],
        ]);

        // La semilla activa al administrador sin pasar por la invitación (DD-22) para poder
        // entrar directamente con los datos de demostración.
        $tenants->firstAdmin($clinic)?->forceFill(['password' => 'password', 'status' => 'activo'])->save();

        $accounts = [
            'dentist' => ['Diego Odontólogo', 'dentista@clinica-demo.test'],
            'receptionist' => ['Rosa Recepcionista', 'recepcion@clinica-demo.test'],
            'patient' => ['Pablo Paciente', 'paciente@clinica-demo.test'],
        ];

        $users = [];
        foreach ($accounts as $role => [$name, $email]) {
            $users[$role] = User::factory()->for($clinic)->create(['name' => $name, 'email' => $email, 'role' => $role]);
        }

        Patient::factory()->for($clinic)->count(20)->create();

        $portalPatient = Patient::factory()->for($clinic)->create([
            'first_name' => 'Pablo',
            'last_name' => 'Paciente',
            'email' => 'paciente@clinica-demo.test',
            'medical_history' => [
                'alergias' => ['Penicilina'],
                'enfermedades' => ['Hipertensión'],
                'medicamentos' => ['Losartán 50 mg'],
                'observaciones' => 'Controla su presión arterial mensualmente.',
            ],
        ]);
        TenantContext::run($clinic, function () use ($portalPatient, $users): void {
            $portalPatient->user_id = $users['patient']->id;
            $portalPatient->save();
        });
    }
}
