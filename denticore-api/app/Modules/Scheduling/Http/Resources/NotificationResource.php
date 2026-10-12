<?php

namespace App\Modules\Scheduling\Http\Resources;

use App\Modules\Scheduling\Models\Notification;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Aviso de la bandeja in-app (CUS-53; RF-158): evento, datos de la plantilla (`params`), enlaces y
 * lectura.
 * El payload no lleva datos personales del paciente (RNF-110).
 *
 * @mixin Notification
 */
class NotificationResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'event' => $this->event->value,
            // `params` y no `data`: un recurso con la clave `data` no se envuelve en `data`.
            'params' => $this->payload['data'],
            'links' => $this->payload['links'],
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
