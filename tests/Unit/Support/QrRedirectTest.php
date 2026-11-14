<?php

declare(strict_types=1);

use UAMSWeb\Aether\Redirects\Support\MarketingRedirect;
use UAMSWeb\Aether\Redirects\Support\QrRedirect;
use UAMSWeb\Aether\Redirects\Support\ValidityWindow;

covers(QrRedirect::class, MarketingRedirect::class);

/**
 * Unit tests for {@see QrRedirect}, the `-QR` redirect that sits beside a vanity URL
 * (UAMS-Web/aether-redirects#14).
 *
 * @command  vendor/bin/pest tests/Unit/Support/QrRedirectTest.php
 */
function qrVanity(string $destination = 'https://www.uams.edu/clinics/flu'): MarketingRedirect
{
    return new MarketingRedirect(
        source: '/v6qzl2N',
        destination: $destination,
        response: '302',
        window: ValidityWindow::fromDates('2026-12-01', '2026-12-31'),
        requester: 'Jane',
        notes: 'Flu clinic poster',
        submittedBy: 'user-1',
        host: 'uams.health',
        generatedBackHalf: true,
        creativeServices: true,
        jobNumber: '12345',
    );
}

it('names the two roles and the tracking values the guidance gives each URL', function (): void {
    expect(QrRedirect::SUFFIX)->toBe('-QR')
        ->and(QrRedirect::PARAMETER)->toBe('utm_specs')
        ->and(QrRedirect::TEXT_SPEC)->toBe('text-url')
        ->and(QrRedirect::QR_SPEC)->toBe('qr')
        ->and(QrRedirect::VANITY)->toBe('vanity')
        ->and(QrRedirect::COMPANION)->toBe('companion');
});

it('appends the suffix to a source and keeps its case', function (): void {
    expect(QrRedirect::source('/v6qzl2N'))->toBe('/v6qzl2N-QR');
});

it('adds the tracking value to a destination with no query', function (): void {
    expect(QrRedirect::withSpec('https://www.uams.edu/clinics/flu', 'qr'))->toBe('https://www.uams.edu/clinics/flu?utm_specs=qr');
});

it('adds the tracking value after the parameters a destination already carries', function (): void {
    expect(QrRedirect::withSpec('https://www.uams.edu/flu?utm_source=a%20b&x=1', 'text-url'))
        ->toBe('https://www.uams.edu/flu?utm_source=a%20b&x=1&utm_specs=text-url');
});

it('replaces a tracking value the destination already carries, wherever it sits', function (): void {
    expect(QrRedirect::withSpec('https://www.uams.edu/flu?utm_specs=old&x=1', 'qr'))->toBe('https://www.uams.edu/flu?x=1&utm_specs=qr')
        ->and(QrRedirect::withSpec('https://www.uams.edu/flu?x=1&utm_specs=old&y=2', 'qr'))->toBe('https://www.uams.edu/flu?x=1&y=2&utm_specs=qr')
        ->and(QrRedirect::withSpec('https://www.uams.edu/flu?utm_specs=old', 'qr'))->toBe('https://www.uams.edu/flu?utm_specs=qr')
        ->and(QrRedirect::withSpec('https://www.uams.edu/flu?utm_specs=a&utm_specs=b', 'qr'))->toBe('https://www.uams.edu/flu?utm_specs=qr');
});

it('replaces a bare tracking parameter that has no value', function (): void {
    expect(QrRedirect::withSpec('https://www.uams.edu/flu?utm_specs&x=1', 'qr'))->toBe('https://www.uams.edu/flu?x=1&utm_specs=qr');
});

it('keeps the fragment after the query', function (): void {
    expect(QrRedirect::withSpec('https://www.uams.edu/flu#top', 'qr'))->toBe('https://www.uams.edu/flu?utm_specs=qr#top')
        ->and(QrRedirect::withSpec('https://www.uams.edu/flu?x=1#top', 'qr'))->toBe('https://www.uams.edu/flu?x=1&utm_specs=qr#top');
});

it('leaves a parameter that only starts with the same letters alone', function (): void {
    expect(QrRedirect::withSpec('https://www.uams.edu/flu?utm_specs_extra=1&xutm_specs=2', 'qr'))
        ->toBe('https://www.uams.edu/flu?utm_specs_extra=1&xutm_specs=2&utm_specs=qr');
});

it('tags the vanity redirect with the text URL value and marks it as having a QR code', function (): void {
    $tagged = QrRedirect::vanity(qrVanity());

    expect($tagged->destination)->toBe('https://www.uams.edu/clinics/flu?utm_specs=text-url')
        ->and($tagged->qrRole)->toBe('vanity')
        ->and($tagged->source)->toBe('/v6qzl2N');
});

it('builds the companion on the same domain and destination with its own tracking value', function (): void {
    $companion = QrRedirect::companion(qrVanity());

    expect($companion->source)->toBe('/v6qzl2N-QR')
        ->and($companion->destination)->toBe('https://www.uams.edu/clinics/flu?utm_specs=qr')
        ->and($companion->host)->toBe('uams.health')
        ->and($companion->qrRole)->toBe('companion');
});

it('carries every other field of the vanity request onto its companion', function (): void {
    $vanity = qrVanity();
    $companion = QrRedirect::companion($vanity);

    expect($companion->response)->toBe('302')
        ->and($companion->window)->toBe($vanity->window)
        ->and($companion->requester)->toBe('Jane')
        ->and($companion->notes)->toBe('Flu clinic poster')
        ->and($companion->submittedBy)->toBe('user-1')
        ->and($companion->generatedBackHalf)->toBeTrue()
        ->and($companion->approvedOnCreate)->toBeFalse()
        ->and($companion->creativeServices)->toBeTrue()
        ->and($companion->jobNumber)->toBe('12345');
});

it('keeps the role in the stored entry data and nowhere else when there is none', function (): void {
    $companion = QrRedirect::companion(qrVanity())->toDomainData('record-1');
    $plain = qrVanity()->toDomainData('record-1');

    expect($companion[QrRedirect::FIELD])->toBe('companion')
        ->and($plain)->not->toHaveKey(QrRedirect::FIELD);
});

it('finds the source of the vanity redirect a companion belongs to, whatever the case of the suffix', function (): void {
    expect(QrRedirect::vanitySource('/v6qzl2N-QR'))->toBe('/v6qzl2N')
        ->and(QrRedirect::vanitySource('/v6qzl2N-qr'))->toBe('/v6qzl2N')
        ->and(QrRedirect::vanitySource('/v6qzl2N-Qr'))->toBe('/v6qzl2N')
        ->and(QrRedirect::vanitySource('/v6qzl2N'))->toBeNull()
        ->and(QrRedirect::vanitySource('/-QR'))->toBeNull()
        ->and(QrRedirect::vanitySource('-QR'))->toBeNull()
        ->and(QrRedirect::vanitySource('/a-QR'))->toBe('/a');
});
