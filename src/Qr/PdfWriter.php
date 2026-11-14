<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Qr;

use FPDF;

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
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->AddPage();
        $pdf->SetFillColor(0, 0, 0);

        foreach ($matrix->darkModules() as [$x, $y]) {
            $pdf->Rect($x * self::UNIT, $y * self::UNIT, self::UNIT, self::UNIT, 'F');
        }

        return $pdf->Output('S');
    }
}
