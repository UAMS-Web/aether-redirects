<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/QrFileReaders.php';

use UAMSWeb\Aether\Redirects\Qr\EpsWriter;
use UAMSWeb\Aether\Redirects\Qr\PdfWriter;
use UAMSWeb\Aether\Redirects\Qr\PngWriter;
use UAMSWeb\Aether\Redirects\Qr\QrMatrix;
use UAMSWeb\Aether\Redirects\Qr\SvgWriter;
use Zxing\QrReader;

covers(QrMatrix::class, SvgWriter::class, EpsWriter::class, PdfWriter::class, PngWriter::class);

/**
 * Unit tests for the QR files (UAMS-Web/aether-redirects#14): each format is written from one matrix, the PNG
 * scans back to the URL, and the SVG, EPS and PDF draw exactly the matrix's modules.
 *
 * @command  vendor/bin/pest tests/Unit/Qr/QrFilesTest.php
 */
const QR_URL = 'https://uams.health/v6qzl2N-QR';

/**
 * Decode PNG bytes with the dev decoder; false when no code is found.
 */
function qrImage(string $png): GdImage
{
    $image = imagecreatefromstring($png);
    assert($image instanceof GdImage);

    return $image;
}

function qrSide(string $png): int
{
    return imagesx(qrImage($png));
}

function qrDecode(string $png): string|false
{
    $path = tempnam(sys_get_temp_dir(), 'qr').'.png';
    file_put_contents($path, $png);

    try {
        $text = (new QrReader($path))->text();

        return is_string($text) ? $text : false;
    } finally {
        unlink($path);
    }
}

it('encodes the URL at error-correction level M with a quiet zone of four modules', function (): void {
    $matrix = QrMatrix::encode(QR_URL);

    expect(QrMatrix::ERROR_CORRECTION)->toBe('M')
        ->and(QrMatrix::QUIET_ZONE)->toBe(4)
        ->and($matrix->size)->toBe(29)
        ->and($matrix->total())->toBe(37);

    foreach ($matrix->darkModules() as [$x, $y]) {
        expect($x)->toBeGreaterThanOrEqual(4)->toBeLessThan(33)
            ->and($y)->toBeGreaterThanOrEqual(4)->toBeLessThan(33);
    }
});

it('draws the finder pattern in the three corners and nowhere else on the corner modules', function (): void {
    $dark = array_map(static fn (array $module): string => $module[0].','.$module[1], QrMatrix::encode(QR_URL)->darkModules());

    // The top left finder is a 7 by 7 ring around a 3 by 3 centre, from module 4,4
    expect($dark)->toContain('4,4', '10,4', '4,10', '10,10', '7,7')
        ->and($dark)->not->toContain('5,5')
        ->and($dark)->toContain('26,4', '32,4', '4,26', '4,32');
});

it('scans the PNG back to the QR URL, and nothing else', function (): void {
    $png = (new PngWriter)->write(QrMatrix::encode(QR_URL));

    expect(qrDecode($png))->toBe(QR_URL);
});

it('writes the PNG at least 1,000 pixels square, with whole pixels per module, black on white', function (): void {
    $png = (new PngWriter)->write(QrMatrix::encode(QR_URL));
    $image = qrImage($png);
    $side = qrSide($png);
    $unit = intdiv($side, 37);

    expect(imagesy($image))->toBe($side)
        ->and($side)->toBeGreaterThanOrEqual(1000)
        ->and($side % 37)->toBe(0)
        ->and(PngWriter::MINIMUM_PIXELS)->toBe(1000);

    $corner = imagecolorsforindex($image, imagecolorat($image, 0, 0));
    $far = imagecolorsforindex($image, imagecolorat($image, $side - 1, $side - 1));
    $finder = imagecolorsforindex($image, imagecolorat($image, 4 * $unit + 1, 4 * $unit + 1));

    // The quiet zone is white and the first finder module is black
    expect($corner)->toMatchArray(['red' => 255, 'green' => 255, 'blue' => 255])
        ->and($far)->toMatchArray(['red' => 255, 'green' => 255, 'blue' => 255])
        ->and($finder)->toMatchArray(['red' => 0, 'green' => 0, 'blue' => 0]);
});

it('does not decode a blank image, or a code with a finder pattern painted over', function (): void {
    $png = (new PngWriter)->write(QrMatrix::encode(QR_URL));
    $image = qrImage($png);
    $unit = intdiv(qrSide($png), 37);
    $white = (int) imagecolorallocate($image, 255, 255, 255);

    // Blank
    $blank = imagecreatetruecolor(1036, 1036);
    assert($blank instanceof GdImage);
    imagefill($blank, 0, 0, (int) imagecolorallocate($blank, 255, 255, 255));
    ob_start();
    imagepng($blank);
    $blankPng = (string) ob_get_clean();

    // Two of the three finder patterns painted out
    imagefilledrectangle($image, 4 * $unit, 4 * $unit, 11 * $unit, 11 * $unit, $white);
    imagefilledrectangle($image, 26 * $unit, 4 * $unit, 33 * $unit, 11 * $unit, $white);
    ob_start();
    imagepng($image);
    $alteredPng = (string) ob_get_clean();

    expect(qrDecode($blankPng))->toBeFalse()
        ->and(qrDecode($alteredPng))->not->toBe(QR_URL);
});

it('draws in the SVG exactly the modules of the matrix', function (): void {
    $matrix = QrMatrix::encode(QR_URL);
    $svg = (new SvgWriter)->write($matrix);

    expect(qrSvgModules($svg))->toBe(qrModulesOf($matrix))
        ->and($svg)->toContain('viewBox="0 0 37 37"')
        ->and($svg)->toContain('fill="#fff"')
        ->and($svg)->toContain('fill="#000"');
});

it('draws in the EPS exactly the modules of the matrix', function (): void {
    $matrix = QrMatrix::encode(QR_URL);
    $eps = (new EpsWriter)->write($matrix);

    expect(qrEpsModules($eps, 37))->toBe(qrModulesOf($matrix))
        ->and($eps)->toStartWith('%!PS-Adobe-3.0 EPSF-3.0')
        ->and($eps)->toContain('%%BoundingBox: 0 0 370 370')
        ->and($eps)->toContain('1 setgray 0 0 370 370 rectfill');
});

it('draws in the PDF exactly the modules of the matrix, on one page of the code size', function (): void {
    $matrix = QrMatrix::encode(QR_URL);
    $pdf = (new PdfWriter)->write($matrix);

    expect(qrPdfModules($pdf, 37))->toBe(qrModulesOf($matrix))
        ->and($pdf)->toStartWith('%PDF-')
        ->and(preg_match_all('/\/Type \/Page\b(?!s)/', $pdf))->toBe(1)
        ->and($pdf)->toContain('/MediaBox [0 0 370.00 370.00]')
        ->and($pdf)->not->toContain('/Subtype /Image');
});

it('fails the comparison when one module is wrong in the SVG, the EPS or the PDF', function (): void {
    $matrix = QrMatrix::encode(QR_URL);
    $expected = qrModulesOf($matrix);
    [$x, $y] = $matrix->darkModules()[100];

    $svg = str_replace(sprintf('M%d %dh1v1h-1z', $x, $y), '', (new SvgWriter)->write($matrix));
    $eps = str_replace(sprintf("%d %d 10 10 rectfill\n", $x * 10, (36 - $y) * 10), '', (new EpsWriter)->write($matrix));
    $pdf = (new PdfWriter)->write($matrix);
    $pdfLine = sprintf("%.2F %.2F 10.00 -10.00 re f\n", $x * 10, (37 - $y) * 10);
    $wrongPdf = str_replace($pdfLine, '', $pdf);

    expect($pdf)->toContain($pdfLine)
        ->and(qrSvgModules($svg))->not->toBe($expected)
        ->and(count(qrSvgModules($svg)))->toBe(count($expected) - 1)
        ->and(qrEpsModules($eps, 37))->not->toBe($expected)
        ->and(count(qrEpsModules($eps, 37)))->toBe(count($expected) - 1)
        ->and(qrPdfModules($wrongPdf, 37))->not->toBe($expected)
        ->and(count(qrPdfModules($wrongPdf, 37)))->toBe(count($expected) - 1);
});

it('names each format by its file extension', function (): void {
    expect((new SvgWriter)->extension())->toBe('svg')
        ->and((new PngWriter)->extension())->toBe('png')
        ->and((new PdfWriter)->extension())->toBe('pdf')
        ->and((new EpsWriter)->extension())->toBe('eps');
});
