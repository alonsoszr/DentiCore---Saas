<?php

namespace App\Support\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * Base de los Resources de la API (SDD §1.3, §4.1; RF-007, DD-19): `id` es siempre el uuid
 * público y ninguna respuesta expone ids numéricos ni claves foráneas `*_id`.
 */
abstract class ApiResource extends JsonResource
{
    /**
     * Atributos del recurso, sin `id` ni claves `*_id` numéricas.
     *
     * @return array<string, mixed>
     */
    abstract protected function fields(Request $request): array;

    /**
     * @return array<string, mixed>
     */
    final public function toArray(Request $request): array
    {
        $attributes = $this->fields($request);

        foreach ($attributes as $key => $value) {
            $isIdKey = $key === 'id' || str_ends_with((string) $key, '_id');

            if ($isIdKey && is_int($value)) {
                throw new LogicException(static::class." no puede exponer la clave «{$key}» (RF-007).");
            }
        }

        return ['id' => $this->resource->uuid, ...$attributes];
    }
}
