<?php

declare(strict_types=1);

use UAMSWeb\Aether\Redirects\Support\RedirectHosts;

covers(RedirectHosts::class);

/**
 * Unit tests for {@see RedirectHosts} (UAMS-Web/aether-redirects#83).
 *
 * @command  vendor/bin/pest tests/Unit/Support/RedirectHostsTest.php
 */
it('proposes the domain a destination calls for', function (string $destination, ?string $expected): void {
    expect(RedirectHosts::proposeFor($destination))->toBe($expected);
})->with([
    'uams.edu' => ['https://uams.edu/news', 'uams.info'],
    'a uams.edu subdomain' => ['https://www.uams.edu/news', 'uams.info'],
    'mixed case and a trailing dot' => ['https://WWW.UAMS.EDU./news', 'uams.info'],
    'uamshealth.com' => ['https://uamshealth.com/', 'uams.health'],
    'a uamshealth.com subdomain' => ['https://www.uamshealth.com/clinics', 'uams.health'],
    'a lookalike suffix' => ['https://notuams.edu/', null],
    'a lookalike prefix' => ['https://uams.edu.example.org/', null],
    'another site' => ['https://example.org/', null],
    'a relative path' => ['/clinics', null],
    'surrounding whitespace' => ['  https://www.uams.edu/news  ', 'uams.info'],
    'an empty string' => ['', null],
]);

it('accepts the pairings a creator may make', function (string $host, string $destination, bool $expected): void {
    expect(RedirectHosts::accepts($host, $destination))->toBe($expected);
})->with([
    'uams.info for uams.edu' => ['uams.info', 'https://www.uams.edu/', true],
    'uams.info for uamshealth.com' => ['uams.info', 'https://uamshealth.com/', false],
    'uams.health for uamshealth.com' => ['uams.health', 'https://uamshealth.com/', true],
    'uams.health for uams.edu' => ['uams.health', 'https://www.uams.edu/', false],
    'uamsbaptist.health for uamshealth.com' => ['uamsbaptist.health', 'https://uamshealth.com/', true],
    'uamsbaptist.health for uams.edu' => ['uamsbaptist.health', 'https://www.uams.edu/', false],
    'a vanity domain for a relative path' => ['uams.info', '/clinics', false],
    'a domain with no pairing rule' => ['go.uams.edu', 'https://example.org/', true],
]);

it('lists the vanity domains, each once and in order', function (): void {
    expect(RedirectHosts::vanity())->toBe(['uams.info', 'uams.health', 'uamsbaptist.health']);
});

it('names the vanity domains', function (): void {
    expect(RedirectHosts::isVanity('uams.info'))->toBeTrue()
        ->and(RedirectHosts::isVanity('uams.health'))->toBeTrue()
        ->and(RedirectHosts::isVanity('uamsbaptist.health'))->toBeTrue()
        ->and(RedirectHosts::isVanity('go.uams.edu'))->toBeFalse()
        ->and(RedirectHosts::isVanity(null))->toBeFalse();
});
