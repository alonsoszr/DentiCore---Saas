<?php

namespace App\Modules\Odontogram\Services;

use App\Modules\Odontogram\Models\FindingCatalog;
use App\Modules\Odontogram\Models\FindingState;

/**
 * Validador clínico central (SDD §5.3; RN-16, RN-17, RN-18, RN-25, RNF-003, RNF-121): la única
 * clase que valida piezas, superficies y hallazgos del catálogo NTS 188. La usan el registro
 * manual, la IA y la importación; los CHECK de la BD (`fn_valid_tooth`, `fn_valid_surfaces`,
 * `fn_same_arch`) aplican la misma tabla como última barrera.
 *
 * No consulta la BD: recibe el hallazgo y el estado ya resueltos por su código.
 */
final class ClinicalValidator
{
    /**
     * Superficies de RN-18 y su nombre en los mensajes.
     */
    private const SURFACE_NAMES = [
        'M' => 'mesial',
        'D' => 'distal',
        'O' => 'oclusal',
        'I' => 'incisal',
        'V' => 'vestibular',
        'L' => 'lingual',
        'P' => 'palatina',
    ];

    /**
     * Motivo por el que la pieza no pertenece al Sistema Dígito Dos (RN-16), o null.
     */
    public function toothError(int $tooth): ?string
    {
        $quadrant = intdiv($tooth, 10);
        $position = $tooth % 10;
        $valid = match (true) {
            $quadrant >= 1 && $quadrant <= 4 => $position >= 1 && $position <= 8,
            $quadrant >= 5 && $quadrant <= 8 => $position >= 1 && $position <= 5,
            default => false,
        };

        return $valid ? null : "La pieza {$tooth} no existe en el Sistema Dígito Dos.";
    }

    /**
     * Motivo por el que la superficie no aplica a la pieza (RN-18), o null. La pieza debe ser
     * válida (toothError).
     */
    public function surfaceError(int $tooth, string $surface): ?string
    {
        if (! array_key_exists($surface, self::SURFACE_NAMES)) {
            return "La superficie «{$surface}» no existe.";
        }

        $anterior = $tooth % 10 <= 3;

        return match (true) {
            $surface === 'O' && $anterior => 'La superficie oclusal no aplica a '.$this->toothKind($tooth).'.',
            $surface === 'I' && ! $anterior => 'La superficie incisal no aplica a '.$this->toothKind($tooth).'.',
            $surface === 'P' && ! $this->isUpper($tooth) => 'La superficie palatina solo aplica a piezas superiores.',
            $surface === 'L' && $this->isUpper($tooth) => 'La superficie lingual solo aplica a piezas inferiores.',
            default => null,
        };
    }

    /**
     * Valida una entrada del odontograma contra RN-16 a RN-18 y RN-25 (SDD §5.3 paso 3).
     *
     * @param  list<string>  $surfaces
     * @return array<string, string> Primer motivo por campo de la solicitud (`tooth`, `tooth_end`,
     *                               `surfaces`, `finding_code`, `state_code`); vacío si es válida.
     */
    public function finding(int $tooth, ?int $toothEnd, array $surfaces, ?FindingCatalog $finding, ?FindingState $state): array
    {
        $errors = [];

        if (($error = $this->toothError($tooth)) !== null) {
            $errors['tooth'] = $error;
        }

        if ($finding === null || ! $finding->is_active) {
            // RN-25: solo hallazgos del catálogo NTS 188 vigente, nunca procedimientos.
            $errors['finding_code'] = 'El hallazgo no pertenece al catálogo NTS 188 vigente.';

            return $errors;
        }

        if ($state === null || ! $state->is_active || $state->finding_id !== $finding->id) {
            $errors['state_code'] = 'El estado no corresponde al hallazgo.';
        }

        if (! isset($errors['tooth'])) {
            if (($error = $this->dentitionError($tooth, $finding)) !== null) {
                $errors['tooth'] = $error;
            } elseif (($error = $this->surfacesError($tooth, $surfaces, $finding)) !== null) {
                $errors['surfaces'] = $error;
            }

            if (($error = $this->spanError($tooth, $toothEnd, $finding)) !== null) {
                $errors['tooth_end'] = $error;
            }
        }

        return $errors;
    }

    /**
     * @param  list<string>  $surfaces
     */
    private function surfacesError(int $tooth, array $surfaces, FindingCatalog $finding): ?string
    {
        if ($finding->level === 'superficie' && $surfaces === []) {
            return "El hallazgo «{$finding->name}» se registra por superficie: indique al menos una.";
        }

        if ($finding->level === 'pieza' && $surfaces !== []) {
            return "El hallazgo «{$finding->name}» se registra por pieza, sin superficies.";
        }

        if (count($surfaces) !== count(array_unique($surfaces))) {
            return 'Cada superficie se indica una sola vez.';
        }

        foreach ($surfaces as $surface) {
            if (($error = $this->surfaceError($tooth, $surface)) !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * Pieza final de un hallazgo de tramo (SRS §11.5 FA-3): existe, es otra pieza y está en el
     * mismo arco.
     */
    private function spanError(int $tooth, ?int $toothEnd, FindingCatalog $finding): ?string
    {
        if ($finding->level !== 'tramo') {
            return $toothEnd === null ? null : 'Solo los hallazgos de tramo admiten pieza final.';
        }

        return match (true) {
            $toothEnd === null => "El hallazgo «{$finding->name}» requiere la pieza final del tramo.",
            $this->toothError($toothEnd) !== null => $this->toothError($toothEnd),
            $toothEnd === $tooth => 'La pieza final del tramo debe ser distinta de la inicial.',
            $this->isUpper($toothEnd) !== $this->isUpper($tooth) => 'La pieza final del tramo debe estar en el mismo arco.',
            default => $this->dentitionError($toothEnd, $finding),
        };
    }

    /**
     * Dentición del hallazgo frente a la pieza: 11–48 permanentes, 51–85 temporales (RN-17).
     */
    private function dentitionError(int $tooth, FindingCatalog $finding): ?string
    {
        $temporary = intdiv($tooth, 10) >= 5;

        return match (true) {
            $finding->dentition === 'permanente' && $temporary => "El hallazgo «{$finding->name}» no aplica a piezas temporales.",
            $finding->dentition === 'temporal' && ! $temporary => "El hallazgo «{$finding->name}» no aplica a piezas permanentes.",
            default => null,
        };
    }

    /**
     * Arco superior: cuadrantes 1, 2, 5 y 6.
     */
    private function isUpper(int $tooth): bool
    {
        return in_array(intdiv($tooth, 10), [1, 2, 5, 6], true);
    }

    /**
     * Tipo de pieza en plural para los mensajes: en la dentición temporal, las posiciones 4 y 5
     * son molares.
     */
    private function toothKind(int $tooth): string
    {
        $position = $tooth % 10;

        return match (true) {
            $position <= 2 => 'incisivos',
            $position === 3 => 'caninos',
            $position <= 5 && intdiv($tooth, 10) <= 4 => 'premolares',
            default => 'molares',
        };
    }
}
