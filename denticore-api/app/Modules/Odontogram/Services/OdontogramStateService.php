<?php

namespace App\Modules\Odontogram\Services;

use App\Modules\Odontogram\Models\InitialOdontogram;
use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Modules\Patients\Models\Patient;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lecturas del odontograma (SDD §2.6 «Estado vigente», §5.3; CUS-21, CUS-24; RF-079 a RF-081,
 * RN-24). El estado vigente no se almacena: se calcula con las entradas del paciente.
 */
class OdontogramStateService
{
    /**
     * RN-24: entradas `inicial`/`evolucion` no corregidas y reemplazos no corregidos, aplicados en
     * orden de registro; por pieza, superficies y hallazgo prevalece la última. Las anulaciones
     * solo excluyen. Con `$at`, el estado a esa fecha (RF-080).
     *
     * @return Collection<int, OdontogramEntry>
     */
    public function current(Patient $patient, ?DateTimeInterface $at = null): Collection
    {
        $entries = $this->entries($patient)
            ->when($at !== null, fn (Builder $query) => $query->where('recorded_at', '<=', $at))
            ->get();
        $corrected = $entries->pluck('corrects_entry_id')->filter()->flip();

        $state = [];
        foreach ($entries as $entry) {
            $applies = in_array($entry->entry_type, ['inicial', 'evolucion'], true) || $entry->correction_kind === 'reemplazo';

            if ($applies && ! $corrected->has($entry->id)) {
                $surfaces = $entry->surfaces;
                sort($surfaces);
                $state[$entry->tooth.'|'.implode(',', $surfaces).'|'.$entry->finding_id] = $entry;
            }
        }

        return (new Collection(array_values($state)))
            ->sortBy([['tooth', 'asc'], fn (OdontogramEntry $a, OdontogramEntry $b) => [$a->recorded_at, $a->id] <=> [$b->recorded_at, $b->id]])
            ->values();
    }

    /**
     * RF-079: el odontograma inicial con sus entradas `inicial` no corregidas, o null si el
     * paciente aún no tuvo su primera atención.
     *
     * @return array{odontogram: InitialOdontogram, entries: Collection<int, OdontogramEntry>}|null
     */
    public function initial(Patient $patient): ?array
    {
        $odontogram = InitialOdontogram::query()->where('patient_id', $patient->id)->first();

        if ($odontogram === null) {
            return null;
        }

        $entries = $this->entries($patient)->get();
        $corrected = $entries->pluck('corrects_entry_id')->filter()->flip();

        return [
            'odontogram' => $odontogram,
            'entries' => $entries
                ->filter(fn (OdontogramEntry $entry) => $entry->entry_type === 'inicial' && ! $corrected->has($entry->id))
                ->values(),
        ];
    }

    /**
     * CUS-24 (RF-081): todas las entradas de la pieza (también como pieza final de un tramo), en
     * orden cronológico, con su corrección si la tienen.
     *
     * @return Collection<int, OdontogramEntry>
     */
    public function toothHistory(Patient $patient, int $tooth): Collection
    {
        return $this->entries($patient)
            ->where(fn (Builder $query) => $query->where('tooth', $tooth)->orWhere('tooth_end', $tooth))
            ->with('correction')
            ->get();
    }

    /**
     * @return Builder<OdontogramEntry>
     */
    private function entries(Patient $patient): Builder
    {
        return OdontogramEntry::query()
            ->where('patient_id', $patient->id)
            ->with(['finding', 'findingState', 'author', 'correctedEntry'])
            ->orderBy('recorded_at')
            ->orderBy('id');
    }
}
