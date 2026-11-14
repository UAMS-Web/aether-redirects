<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Qr;

/**
 * Writes a QR matrix as the bytes of one file format (UAMS-Web/aether-redirects#14).
 */
interface QrWriter
{
    /**
     * The file extension and the key a file is stored under.
     */
    public function extension(): string;

    /**
     * Write the matrix: black modules on white, with the quiet zone around them.
     *
     * @param  QrMatrix  $matrix  The code to write
     * @return string The file's bytes
     */
    public function write(QrMatrix $matrix): string;
}
