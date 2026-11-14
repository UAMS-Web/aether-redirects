<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Qr;

use FPDF;
use RuntimeException;

/**
 * The print master: a one-page PDF of vector squares, one per dark module (UAMS-Web/aether-redirects#14).
 *
 * The content stream is left uncompressed. The page is small, and a stream a reader can read is one a test
 * can check against the matrix.
 */
final class PdfWriter implements QrWriter
{
    /**
     * The side of a module in points.
     */
    // PCOV attributes no coverage to a constant's initializer, so Pest selects no test for its mutants and
    // reports them uncovered. A changed value is observable, and the media box assertion in `QrFilesTest` pins it.
    // @pest-mutate-ignore:IncrementInteger,DecrementInteger
    public const int UNIT = 10;

    public function extension(): string
    {
        return 'pdf';
    }

    public function write(QrMatrix $matrix): string
    {
        $side = $matrix->total() * self::UNIT;

        $pdf = new FPDF('P', 'pt', [$side, $side]);
        $pdf->SetCompression(false);
        $pdf->AddPage();
        $pdf->SetFillColor(0, 0, 0);

        foreach ($matrix->darkModules() as [$x, $y]) {
            $pdf->Rect($x * self::UNIT, $y * self::UNIT, self::UNIT, self::UNIT, 'F');
        }

        $document = $pdf->Output('S');

        if (! is_string($document)) {
            throw new RuntimeException('FPDF did not return the document.');
        }

        return $document;
    }
}
