<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Intento de cambiar el tenant_id de un registro de clínica: es inmutable (SDD §1.6.3).
 */
class TenantMutationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('El tenant_id de un registro de clínica no puede cambiar.');
    }
}
