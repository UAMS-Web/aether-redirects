<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Qr;

use RuntimeException;

/**
 * A raster for places a vector will not do, at least {@see MINIMUM_PIXELS} pixels square (UAMS-Web/aether-redirects#14).
 *
 * Written from the matrix with GD: the module side is a whole number of pixels, so no module is blurred.
 */
final class PngWriter implements QrWriter
{
    /**
     * The least number of pixels along a side.
     */
    // PCOV attributes no coverage to a constant's initializer, so Pest selects no test for its mutants and
    // reports them uncovered. A changed value is observable, and the size assertions in `QrFilesTest` pin it.
    // @pest-mutate-ignore:IncrementInteger,DecrementInteger
    public const int MINIMUM_PIXELS = 1000;

    public function extension(): string
    {
        return 'png';
    }

    public function write(QrMatrix $matrix): string
    {
        $total = $matrix->total();
        $unit = (int) ceil(self::MINIMUM_PIXELS / $total);

        // GD refuses a side below one pixel, which a matrix with modules can never be; the clamp only
        // gives the analyzer the range, so no test can observe a different bound.
        // @pest-mutate-ignore:DecrementInteger,IncrementInteger
        $side = max(1, $total * $unit);

        $image = imagecreatetruecolor($side, $side);

        // GD could not allocate the image, which no test can cause, so the opposite comparison is not observable.
        // @pest-mutate-ignore:FalseToTrue
        if ($image === false) {
            throw new RuntimeException('GD could not create the image.');
        }

        // A fill from any pixel of a blank image spreads over all of it, so another starting point paints the same pixels.
        // @pest-mutate-ignore:IncrementInteger
        imagefill($image, 0, 0, 0xFFFFFF);

        foreach ($matrix->darkModules() as [$x, $y]) {
            imagefilledrectangle($image, $x * $unit, $y * $unit, ($x + 1) * $unit - 1, ($y + 1) * $unit - 1, 0x000000);
        }

        ob_start();
        imagepng($image);

        // `ob_get_clean()` returns the string here, so the cast changes nothing a caller can see; it keeps the return type exact.
        // @pest-mutate-ignore:RemoveStringCast
        return (string) ob_get_clean();
    }
}
