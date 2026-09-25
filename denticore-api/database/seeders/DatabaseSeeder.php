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

        $clinic = $tenants->create(
            ['name' => 'Clínica Demo', 'slug' => 'clinica-demo', 'subscription_plan' => 'pro'],
            ['name' => 'Carla Administradora', 'email' => 'admin@clinica-demo.test', 'password' => 'password'],
        );

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
        $portalPatient->user_id = $users['patient']->id;
        $portalPatient->save();
    }
}
