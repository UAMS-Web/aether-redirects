<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Qr;

/**
 * An EPS for vendors that still ask for one, drawn from the matrix as the other formats are (UAMS-Web/aether-redirects#14).
 *
 * Each module is a square of {@see UNIT} points, and the origin is the bottom left as PostScript has it.
 */
final class EpsWriter implements QrWriter
{
    /**
     * The side of a module in points.
     */
    // PCOV attributes no coverage to a constant's initializer, so Pest selects no test for its mutants and
    // reports them uncovered. A changed value is observable, and the size assertions in `QrFilesTest` pin it.
    // @pest-mutate-ignore:IncrementInteger,DecrementInteger
    public const int UNIT = 10;

    public function extension(): string
    {
        return 'eps';
    }

    public function write(QrMatrix $matrix): string
    {
        $total = $matrix->total();
        $side = $total * self::UNIT;

        $lines = [
            '%!PS-Adobe-3.0 EPSF-3.0',
            sprintf('%%%%BoundingBox: 0 0 %d %d', $side, $side),
            '%%EndComments',
            sprintf('1 setgray 0 0 %d %d rectfill', $side, $side),
            '0 setgray',
        ];

        foreach ($matrix->darkModules() as [$x, $y]) {
            $lines[] = sprintf('%d %d %d %d rectfill', $x * self::UNIT, ($total - 1 - $y) * self::UNIT, self::UNIT, self::UNIT);
        }

        $lines[] = 'showpage';
        $lines[] = '%%EOF';

        return implode("\n", $lines)."\n";
    }
}
