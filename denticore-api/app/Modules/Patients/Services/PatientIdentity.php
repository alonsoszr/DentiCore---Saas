<?php

namespace App\Modules\Patients\Services;

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

    private static function prefix(string $type): string
    {
        return self::DOCUMENT_PREFIXES[$type] ?? throw new InvalidArgumentException("Tipo de documento desconocido: {$type}.");
    }
}
