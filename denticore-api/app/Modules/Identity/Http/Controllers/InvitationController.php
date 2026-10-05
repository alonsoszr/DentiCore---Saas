<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\InvitationService;
use App\Modules\Identity\Services\PasswordPolicy;
use App\Support\Http\Controller;
use App\Support\Tokens\OneTimeToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Activación de cuenta por invitación (SDD §4.3.2; CUS-01 FA-2, CUS-11; RF-016, RF-040, DD-22).
 * El token lo resuelve `tenant.token:invitacion`: vencido, usado o inexistente → el mismo 404.
 */
class InvitationController extends Controller
{
    public function __construct(private InvitationService $invitations) {}

    /**
     * Datos de la pantalla de activación: nombre, correo, rol y clínica.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->invitedUser($request)->loadMissing('tenant');

        return response()->json(['data' => [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'clinic' => $user->tenant === null ? null : ['name' => $user->tenant->name, 'slug' => $user->tenant->slug],
        ]]);
    }

    /**
     * Define la contraseña y activa la cuenta. Responde 204: la SPA lleva al inicio de sesión
     * (PEND-05), donde el administrador configura su segundo factor.
     */
    public function accept(Request $request): Response
    {
        /** @var OneTimeToken $token */
        $token = $request->attributes->get('one_time_token');
        $user = $this->invitedUser($request);

        /** @var array{password: string} $data */
        $data = $request->validate([
            'password' => ['required', 'string', 'confirmed', new PasswordPolicy($user)],
        ], [], ['password' => 'contraseña']);

        $this->invitations->accept($token, $user, $data['password']);

        return response()->noContent();
    }

    private function invitedUser(Request $request): User
    {
        /** @var OneTimeToken $token */
        $token = $request->attributes->get('one_time_token');

        return User::query()->where('status', 'pendiente_activacion')->findOrFail($token->tokenable_id);
    }
}
