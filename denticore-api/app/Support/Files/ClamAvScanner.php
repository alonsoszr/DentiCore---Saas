<?php

namespace App\Support\Files;

use RuntimeException;

/**
 * Cliente de clamd por TCP con el comando INSTREAM (supuesto S-08: ClamAV).
 */
class ClamAvScanner implements VirusScanner
{
    private const CHUNK_BYTES = 8192;

    public function __construct(
        private string $host,
        private int $port,
        private int $timeoutSeconds = 30,
    ) {}

    public function isClean($stream): bool
    {
        $socket = @fsockopen($this->host, $this->port, $errorCode, $errorMessage, $this->timeoutSeconds);

        if ($socket === false) {
            throw new RuntimeException("No se pudo conectar con el antivirus: {$errorMessage}");
        }

        try {
            stream_set_timeout($socket, $this->timeoutSeconds);
            fwrite($socket, "zINSTREAM\0");

            while (! feof($stream)) {
                $chunk = (string) fread($stream, self::CHUNK_BYTES);

                if ($chunk === '') {
                    break;
                }

                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }

            fwrite($socket, pack('N', 0));
            $reply = trim((string) stream_get_contents($socket), "\0\n ");
        } finally {
            fclose($socket);
        }

        if (str_ends_with($reply, 'OK')) {
            return true;
        }

        if (str_ends_with($reply, 'FOUND')) {
            return false;
        }

        throw new RuntimeException("Respuesta inesperada del antivirus: {$reply}");
    }
}
