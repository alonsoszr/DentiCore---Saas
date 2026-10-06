<?php

use App\Modules\Scheduling\Jobs\SendNotificationJob;
use App\Support\Files\GenerateDocumentJob;
use App\Support\Files\ScanStoredFileJob;

return [

    /*
    |--------------------------------------------------------------------------
    | Jobs del outbox (SDD §1.9, DD-41)
    |--------------------------------------------------------------------------
    |
    | Tipo de mensaje => clase del job (subclase de App\Support\Outbox\OutboxJob). Cada
    | módulo registra aquí los tipos que emite (p. ej. las notificaciones de TASK-022).
    |
    */

    'handlers' => [
        'stored_file.scan' => ScanStoredFileJob::class,
        'document.generate' => GenerateDocumentJob::class,
        'notification.send' => SendNotificationJob::class,
    ],

    // Días que se conservan los mensajes despachados antes de `outbox:prune` (SDD §1.9).
    'prune_after_days' => 7,

];
