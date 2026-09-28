<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cadenas de hashes verificadas por `integrity:verify` (SDD §1.7, §1.9)
    |--------------------------------------------------------------------------
    |
    | table: tabla (padre, si está particionada); scope: columna que agrupa cada cadena;
    | exclude: columna excluida del hash (opcional). Cada módulo agrega las suyas
    | (p. ej. odontogram_entries en TASK-045).
    |
    */

    'chains' => [
        ['table' => 'audit_logs', 'scope' => 'tenant_id'],
    ],

];
