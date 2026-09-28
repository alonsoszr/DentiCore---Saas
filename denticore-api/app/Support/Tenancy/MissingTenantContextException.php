<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Operación sobre datos de clínica sin una clínica resuelta (SDD §1.6.1, RN-02).
 */
class MissingTenantContextException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No hay una clínica activa para esta operación.');
    }
}
