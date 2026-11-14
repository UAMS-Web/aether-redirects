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
    public const int MINIMUM_PIXELS = 1000;

    public function extension(): string
    {
        return 'png';
    }

    public function write(QrMatrix $matrix): string
    {
        $total = $matrix->total();
        $unit = intdiv(self::MINIMUM_PIXELS + $total - 1, $total);
        $side = $total * $unit;

        $image = imagecreate($side, $side);

        if ($image === false) {
            throw new RuntimeException('GD could not create the image.');
        }

        imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);

        foreach ($matrix->darkModules() as [$x, $y]) {
            imagefilledrectangle($image, $x * $unit, $y * $unit, ($x + 1) * $unit - 1, ($y + 1) * $unit - 1, (int) $black);
        }

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        return $png;
    }
}
