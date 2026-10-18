<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Segundo factor (SDD §4.3.2, §3.6; CUS-07, CUS-08, CUS-78).
 */
class TwoFactorController extends Controller
{
    public function __construct(private TwoFactorService $twoFactor) {}

    /**
     * CUS-08: secreto y URL `otpauth://` para escanear con la aplicación autenticadora.
     */
    public function setup(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->twoFactor->setup($user));
    }

    /**
     * CUS-08: activa el 2FA, devuelve los 10 códigos de recuperación (se muestran una sola vez)
     * y un token `full` que reemplaza al actual (PEND-04).
     */
    public function confirm(Request $request): JsonResponse
    {
        /** @var array{code: string} $data */
        $data = $request->validate(['code' => ['required', 'string', 'size:6']], [], ['code' => 'código']);
        /** @var User $user */
        $user = $request->user();

        $session = $this->twoFactor->confirm($user, $data['code'], $request->ip(), $request->userAgent());

        return response()->json([
            'recovery_codes' => $session['recovery_codes'],
            ...$this->sessionPayload($session),
        ]);
    }

    /**
     * CUS-07: código TOTP o código de recuperación.
     */
    public function verify(Request $request): JsonResponse
    {
        /** @var array{code?: string, recovery_code?: string} $data */
        $data = $request->validate([
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'size:6'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:20'],
        ], [], ['code' => 'código', 'recovery_code' => 'código de recuperación']);
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->sessionPayload($this->twoFactor->verify(
            $user, $data['code'] ?? null, $data['recovery_code'] ?? null, $request->ip(), $request->userAgent(),
        )));
    }

    /**
     * CUS-78 (RF-051, DD-36): 10 códigos de recuperación nuevos (se muestran una sola vez); los
     * anteriores dejan de servir.
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['recovery_codes' => $this->twoFactor->regenerateRecoveryCodes($user, $this->totpCode($request))]);
    }

    /**
     * CUS-78 (RF-051): desactiva el segundo factor en los roles donde es opcional.
     */
    #[ProblemResponse(403, 'El segundo factor es obligatorio para el Súper Administrador y el Administrador de Clínica (RF-051)')]
    public function disable(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $this->twoFactor->disable($user, $this->totpCode($request));

        return response()->noContent();
    }

    private function totpCode(Request $request): string
    {
        /** @var array{code: string} $data */
        $data = $request->validate(['code' => ['required', 'string', 'size:6']], [], ['code' => 'código']);

        return $data['code'];
    }

    /**
     * @param  array{token: string, abilities: list<string>, expires_at: \DateTimeInterface|null, user: User}  $session
     * @return array<string, mixed>
     */
    private function sessionPayload(array $session): array
    {
        return [
            'token' => $session['token'],
            'abilities' => $session['abilities'],
            'requires_2fa' => false,
            'requires_2fa_setup' => false,
            'expires_at' => $session['expires_at'],
            'user' => UserResource::make($session['user']),
        ];
    }
}
