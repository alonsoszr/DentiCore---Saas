<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Política de contraseñas (SDD §1.7; DD-15, RF-040, RNF-093): 10 a 128 caracteres, fuera de la
 * lista de ≥ 10 000 contraseñas comunes (`storage/app/security/common-passwords.txt`), sin el
 * correo ni el nombre del usuario y distinta de sus 5 últimas contraseñas.
 */
class PasswordPolicy implements ValidationRule
{
    public const MIN_LENGTH = 10;

    public const MAX_LENGTH = 128;

    public const HISTORY = 5;

    /** @var array<string, true>|null */
    private static ?array $common = null;

    public function __construct(private ?User $user = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('La contraseña no es válida.');

            return;
        }

        $length = mb_strlen($value);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            $fail('La contraseña debe tener entre 10 y 128 caracteres.');
        }

        if (isset(self::commonPasswords()[mb_strtolower($value)])) {
            $fail('La contraseña es demasiado común. Elige otra.');
        }

        if ($this->user !== null && $this->containsPersonalData($value, $this->user)) {
            $fail('La contraseña no puede contener tu correo ni tu nombre.');
        }

        if ($this->user !== null && $this->wasRecentlyUsed($value, $this->user)) {
            $fail('La contraseña no puede ser igual a ninguna de tus 5 últimas contraseñas.');
        }
    }

    public static function commonPasswordCount(): int
    {
        return count(self::commonPasswords());
    }

    /**
     * @return array<string, true>
     */
    private static function commonPasswords(): array
    {
        if (self::$common === null) {
            $lines = file(storage_path('app/security/common-passwords.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            self::$common = array_fill_keys(array_map(fn (string $line): string => mb_strtolower(trim($line)), $lines), true);
        }

        return self::$common;
    }

    /**
     * El correo completo, su parte local o cualquier palabra del nombre (de 3 o más letras).
     */
    private function containsPersonalData(string $password, User $user): bool
    {
        $haystack = mb_strtolower($password);
        $email = mb_strtolower($user->email);
        $needles = [$email, strstr($email, '@', true) ?: $email];

        foreach (preg_split('/[\s\'-]+/u', mb_strtolower($user->name)) ?: [] as $word) {
            $needles[] = $word;
        }

        foreach ($needles as $needle) {
            if (mb_strlen($needle) >= 3 && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function wasRecentlyUsed(string $password, User $user): bool
    {
        // La contraseña vigente cuenta aunque no esté en el historial (cuentas anteriores a TASK-029).
        $hashes = DB::table('user_password_histories')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(self::HISTORY)
            ->pluck('password_hash')
            ->push($user->password)
            ->filter();

        foreach ($hashes as $hash) {
            if (Hash::check($password, (string) $hash)) {
                return true;
            }
        }

        return false;
    }
}
