<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Qr;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * The modules of a QR code for one URL (UAMS-Web/aether-redirects#14).
 *
 * Every file format is written from this one matrix, so the SVG, PNG, PDF and EPS cannot draw
 * different codes. The matrix includes no margin: a writer adds the quiet zone around it, which
 * is {@see QUIET_ZONE} modules on every side, the 4 the QR specification asks for.
 */
final readonly class QrMatrix
{
    /**
     * The quiet zone around the modules, in modules.
     */
    // PCOV attributes no coverage to a constant's initializer, so Pest selects no test for its mutants and
    // reports them uncovered. A changed value is observable, and the size assertions in `QrFilesTest` pin it.
    // @pest-mutate-ignore:IncrementInteger,DecrementInteger
    public const int QUIET_ZONE = 4;

    /**
     * The error-correction level, level M: about 15% of the code can be damaged and still scan.
     *
     * The code encodes a short URL, so level M keeps the module count low and the modules large
     * when printed, which scans better than a denser code at a higher level.
     */
    public const string ERROR_CORRECTION = 'M';

    /**
     * @param  int  $size  The modules along one side, without the quiet zone
     * @param  list<list<bool>>  $rows  Whether each module is dark, by row and then column
     */
    private function __construct(
        public int $size,
        private array $rows,
    ) {}

    /**
     * Encode a URL.
     *
     * @param  string  $content  The text the code carries
     */
    public static function encode(string $content): self
    {
        $matrix = Encoder::encode($content, ErrorCorrectionLevel::valueOf(self::ERROR_CORRECTION), Encoder::DEFAULT_BYTE_MODE_ENCODING)->getMatrix();

        $rows = [];

        for ($y = 0; $y < $matrix->getHeight(); $y++) {
            $row = [];

            for ($x = 0; $x < $matrix->getWidth(); $x++) {
                $row[] = $matrix->get($x, $y) === 1;
            }

            $rows[] = $row;
        }

        return new self($matrix->getWidth(), $rows);
    }

    /**
     * The modules along one side with the quiet zone on both sides.
     */
    public function total(): int
    {
        return $this->size + 2 * self::QUIET_ZONE;
    }

    /**
     * The dark modules, as columns and rows counted from the top left of the code with the quiet zone included.
     *
     * @return list<array{int, int}>
     */
    public function darkModules(): array
    {
        $dark = [];

        foreach ($this->rows as $y => $row) {
            foreach ($row as $x => $isDark) {
                if ($isDark) {
                    $dark[] = [$x + self::QUIET_ZONE, $y + self::QUIET_ZONE];
                }
            }
        }

        return $dark;
    }
}
