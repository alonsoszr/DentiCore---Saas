<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\PasswordPolicy;
use App\Modules\Identity\Services\PasswordResetService;
use App\Support\Http\Controller;
use App\Support\Tenancy\TenantContext;
use App\Support\Tokens\OneTimeToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Recuperación de contraseña (SDD §4.3.2; CUS-09; RF-039, RF-040).
 */
class PasswordResetController extends Controller
{
    public function __construct(private PasswordResetService $resets) {}

    /**
     * RF-039: la misma respuesta (204) exista o no el correo.
     */
    public function requestLink(Request $request): Response
    {
        /** @var array{email: string} $data */
        $data = $request->validate(['email' => ['required', 'email', 'max:180']], [], ['email' => 'correo electrónico']);

        $this->resets->requestLink(TenantContext::tenantOrFail(), $data['email']);

        return response()->noContent();
    }

    /**
     * El token (resuelto por `tenant.token`) es de un solo uso y vence a los 60 minutos.
     */
    public function reset(Request $request): Response
    {
        /** @var OneTimeToken $token */
        $token = $request->attributes->get('one_time_token');
        $user = User::query()->findOrFail($token->tokenable_id);

        /** @var array{password: string} $data */
        $data = $request->validate([
            'password' => ['required', 'string', 'confirmed', new PasswordPolicy($user)],
        ], [], ['password' => 'contraseña']);

        $this->resets->reset($token, $data['password']);

        return response()->noContent();
    }
}
