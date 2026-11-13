<?php

declare(strict_types=1);

use UAMSWeb\Aether\Redirects\Support\TrustedDestination;

covers(TrustedDestination::class);

/**
 * Unit tests for {@see TrustedDestination}: whether a short link's destination is
 * on a host the application already serves (UAMS-Web/aether-redirects#84).
 *
 * @covers \UAMSWeb\Aether\Redirects\Support\TrustedDestination
 *
 * @command  vendor/bin/pest tests/Unit/Support/TrustedDestinationTest.php
 */

/**
 * The worked example: the application at uams.edu, one site on uamshealth.com, and
 * the domain list's uams.health.
 */
function trustedExample(): TrustedDestination
{
    return new TrustedDestination('https://uams.edu', ['  uamshealth.com ', 'UAMS.Health.', '', '  ', '.']);
}

it('trusts the application host, its subdomains and the exact hosts', function (string $destination): void {
    expect(trustedExample()->allows($destination))->toBeTrue();
})->with([
    'the application host' => ['https://uams.edu/flu'],
    'a subdomain' => ['https://news.uams.edu/2026/flu'],
    'a deeper subdomain, plain http, a port and a query' => ['http://a.b.uams.edu:8080/x?y=1'],
    'a site domain' => ['https://uamshealth.com/'],
    'a domain list host in another case with a trailing dot' => ['HTTPS://UAMS.HEALTH./flu'],
    'surrounding whitespace' => ['  https://uams.edu/flu  '],
]);

it('does not trust any other destination', function (string $destination): void {
    expect(trustedExample()->allows($destination))->toBeFalse();
})->with([
    'another domain' => ['https://example.com/'],
    'a domain ending in the application host without a dot' => ['https://notuams.edu/'],
    'the application host as a subdomain of another' => ['https://uams.edu.example.com/'],
    'a subdomain of an exact host' => ['https://www.uamshealth.com/'],
    'user info that names a trusted host' => ['https://uams.edu@example.com/'],
    'a relative path' => ['/flu'],
    'another scheme' => ['ftp://uams.edu/file'],
    'javascript' => ['javascript:alert(1)'],
    'no host' => ['https:///flu'],
    'not a URL' => ['http://:80'],
    'empty' => [''],
    'a host of only a dot, against an empty exact host' => ['https://./flu'],
]);

it('compares hosts as text, never as numbers', function (): void {
    expect(new TrustedDestination(null, ['1e1'])->allows('http://10/'))->toBeFalse()
        ->and(new TrustedDestination(null, ['10'])->allows('http://10/'))->toBeTrue();
});

it('trusts only the exact hosts when the application has no URL', function (): void {
    $trusted = new TrustedDestination(null, ['uamshealth.com']);

    expect($trusted->allows('https://uamshealth.com/'))->toBeTrue()
        ->and($trusted->allows('https://uams.edu/'))->toBeFalse()
        ->and(new TrustedDestination('not a url', [])->allows('https://uams.edu/'))->toBeFalse();
});

it('reads the normalized host of an http or https URL', function (string $url, ?string $host): void {
    expect(TrustedDestination::hostOf($url))->toBe($host);
})->with([
    'https' => ['https://News.UAMS.edu./x', 'news.uams.edu'],
    'http' => ['http://uams.edu', 'uams.edu'],
    'no scheme' => ['uams.edu/flu', null],
    'mailto' => ['mailto:web@uams.edu', null],
]);
