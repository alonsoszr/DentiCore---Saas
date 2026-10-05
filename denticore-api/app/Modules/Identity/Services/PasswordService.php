<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cambio de contraseña (DD-15, RF-040): guarda el hash bcrypt, registra el historial (se
 * conservan las 5 últimas, `user_password_histories`) y revoca todas las sesiones del usuario.
 * La política la valida PasswordPolicy en el Form Request.
 */
class PasswordService
{
    public function set(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password): void {
            $user->forceFill(['password' => $password, 'password_changed_at' => now()])->save();

            DB::table('user_password_histories')->insert([
                'user_id' => $user->id,
                'password_hash' => $user->password,
                'created_at' => now(),
            ]);

            $keep = DB::table('user_password_histories')->where('user_id', $user->id)
                ->orderByDesc('id')->limit(PasswordPolicy::HISTORY)->pluck('id');
            DB::table('user_password_histories')->where('user_id', $user->id)->whereNotIn('id', $keep)->delete();

            $user->tokens()->delete();
        });
    }
}
