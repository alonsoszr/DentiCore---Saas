<?php

namespace App\Support\Files;

use RuntimeException;

/**
 * El archivo no se entrega porque su escaneo antivirus está pendiente o dio positivo
 * (RNF-103: solo `limpio` se entrega).
 */
class FileNotDeliverableException extends RuntimeException
{
    public function __construct(public readonly string $scanStatus)
    {
        parent::__construct("El archivo no está disponible para descarga (estado del escaneo: {$scanStatus}).");
    }
}
