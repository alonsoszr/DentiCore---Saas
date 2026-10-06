<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cadenas de hashes verificadas por `integrity:verify` (SDD §1.7, §1.9)
    |--------------------------------------------------------------------------
    |
    | table: tabla (padre, si está particionada); scope: columna que agrupa cada cadena;
    | exclude: columna excluida del hash (opcional). Cada módulo agrega las suyas.
    | El odontograma encadena por paciente original y excluye patient_id, que solo
    | cambia en una fusión de fichas (DD-37, DI-21).
    |
    */

    'chains' => [
        ['table' => 'audit_logs', 'scope' => 'tenant_id'],
        ['table' => 'odontogram_entries', 'scope' => 'chain_patient_id', 'exclude' => 'patient_id'],
    ],

];
