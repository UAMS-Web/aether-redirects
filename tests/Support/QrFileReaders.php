<?php

declare(strict_types=1);

use UAMSWeb\Aether\Redirects\Qr\EpsWriter;
use UAMSWeb\Aether\Redirects\Qr\PdfWriter;
use UAMSWeb\Aether\Redirects\Qr\QrMatrix;

/**
 * Read the dark modules back out of a written file, as columns and rows counted from the top left with
 * the quiet zone included, sorted. A test compares them with the matrix the writer was given
 * (UAMS-Web/aether-redirects#14).
 */

/**
 * The modules an SVG draws.
 *
 * @return list<array{int, int}>
 */
function qrSvgModules(string $svg): array
{
    preg_match_all('/M(\d+) (\d+)h1v1h-1z/', $svg, $found, PREG_SET_ORDER);

    return qrSorted(array_map(static fn (array $match): array => [(int) $match[1], (int) $match[2]], $found));
}

/**
 * The modules an EPS draws; its rows count from the bottom, so they are turned over.
 *
 * @return list<array{int, int}>
 */
function qrEpsModules(string $eps, int $total): array
{
    $unit = EpsWriter::UNIT;
    preg_match_all('/^(\d+) (\d+) '.$unit.' '.$unit.' rectfill$/m', $eps, $found, PREG_SET_ORDER);

    return qrSorted(array_map(static fn (array $match): array => [intdiv((int) $match[1], $unit), $total - 1 - intdiv((int) $match[2], $unit)], $found));
}

/**
 * The modules a PDF draws, read from its uncompressed content stream; its rows count from the bottom.
 *
 * @return list<array{int, int}>
 */
function qrPdfModules(string $pdf, int $total): array
{
    $unit = PdfWriter::UNIT;
    $side = $total * $unit;
    preg_match_all('/^([\d.]+) ([\d.]+) '.$unit.'\.00 -'.$unit.'\.00 re f$/m', $pdf, $found, PREG_SET_ORDER);

    return qrSorted(array_map(static fn (array $match): array => [(int) round((float) $match[1] / $unit), (int) round(($side - (float) $match[2]) / $unit)], $found));
}

/**
 * The modules of a matrix, sorted as the readers sort theirs.
 *
 * @return list<array{int, int}>
 */
function qrModulesOf(QrMatrix $matrix): array
{
    return qrSorted($matrix->darkModules());
}

/**
 * @param  list<array{int, int}>  $modules
 * @return list<array{int, int}>
 */
function qrSorted(array $modules): array
{
    usort($modules, static fn (array $a, array $b): int => [$a[1], $a[0]] <=> [$b[1], $b[0]]);

    return $modules;
}
