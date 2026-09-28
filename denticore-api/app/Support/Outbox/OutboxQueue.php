<?php

namespace App\Support\Outbox;

/**
 * Colas de SDD §1.9: `critical` (alertas de riesgo, incidentes, plazos ARCO),
 * `notifications` (correos e in-app), `documents` (PDF) y `heavy` (exportaciones,
 * importaciones, rotación de claves, eliminación de clínica).
 */
enum OutboxQueue: string
{
    case Critical = 'critical';
    case Notifications = 'notifications';
    case Documents = 'documents';
    case Heavy = 'heavy';
}
