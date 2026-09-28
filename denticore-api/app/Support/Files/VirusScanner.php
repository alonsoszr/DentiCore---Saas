<?php

namespace App\Support\Files;

/**
 * Escáner antivirus de archivos subidos (SDD DI-16, RNF-103). La herramienta no está definida
 * en el SDD (pregunta PL-02 del plan); la implementación por defecto usa ClamAV (supuesto S-08).
 */
interface VirusScanner
{
    /**
     * @param  resource  $stream
     * @return bool true si el archivo está limpio.
     */
    public function isClean($stream): bool;
}
