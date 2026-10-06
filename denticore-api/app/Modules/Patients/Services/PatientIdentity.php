<?php

namespace App\Modules\Patients\Services;

use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Normalizaciones de la identidad del paciente (SDD §1.7.1, §2.5; RN-09, RN-79, DI-07, DI-14):
 * - índice ciego del documento sobre `TIPO:NUMERO` en mayúsculas (`DNI:`, `CE:`, `PAS:`, `CPP:`);
 * - número de historia clínica = DNI, o el número con prefijo `CE-`, `PAS-`, `CPP-`;
 * - `search_name` en minúsculas y sin tildes para la búsqueda por trigramas.
 */
final class PatientIdentity
{
    /** @var array<string, string> */
    public const DOCUMENT_PREFIXES = ['dni' => 'DNI', 'ce' => 'CE', 'pasaporte' => 'PAS', 'cpp' => 'CPP'];

    public static function normalizedNumber(string $number): string
    {
        return Str::upper(trim($number));
    }

    public static function normalizedDocument(string $type, string $number): string
    {
        return self::prefix($type).':'.self::normalizedNumber($number);
    }

    public static function clinicalRecordNumber(string $type, string $number): string
    {
        $number = self::normalizedNumber($number);

        return $type === 'dni' ? $number : self::prefix($type).'-'.$number;
    }

    public static function searchName(string $firstName, string $lastName): string
    {
        return Str::of(Str::ascii(trim($firstName).' '.trim($lastName)))->lower()->squish()->toString();
    }

    /**
     * SRS §11.3: DNI de 8 dígitos; carné de extranjería y CPP de 9 a 12 alfanuméricos; pasaporte
     * de 6 a 12 alfanuméricos (sin espacios al inicio ni al final, en mayúsculas).
     */
    public static function validDocumentNumber(string $type, string $number): bool
    {
        $pattern = match ($type) {
            'dni' => '/^\d{8}$/',
            'ce', 'cpp' => '/^[A-Z0-9]{9,12}$/',
            'pasaporte' => '/^[A-Z0-9]{6,12}$/',
            default => null,
        };

        return $pattern !== null && preg_match($pattern, self::normalizedNumber($number)) === 1;
    }

    /**
     * SRS §11.3: celular peruano de 9 dígitos que empieza en 9, o número internacional E.164.
     */
    public const PHONE_PATTERN = '/^(9\d{8}|\+[1-9]\d{7,14})$/';

    /**
     * Regla de validación del número de documento según el campo del tipo.
     */
    public static function documentNumberRule(string $typeField): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($typeField): void {
            $type = request()->input($typeField);

            if (! is_string($value) || ! is_string($type) || ! self::validDocumentNumber($type, $value)) {
                $fail('El número de documento no tiene el formato del tipo de documento elegido.');
            }
        };
    }

    private static function prefix(string $type): string
    {
        return self::DOCUMENT_PREFIXES[$type] ?? throw new InvalidArgumentException("Tipo de documento desconocido: {$type}.");
    }
}
