<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainList;
use UAMSWeb\Aether\Redirects\Services\TrustedDestinationReader;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

// Stache-isolated: tests save their own domain list records.
uses(IsolatesStache::class);

covers(TrustedDestinationReader::class);

/**
 * Integration tests for {@see TrustedDestinationReader}: the application URL, the
 * sites and the domain list read into the trusted-host check (UAMS-Web/aether-redirects#84).
 *
 * @command  vendor/bin/pest tests/Integration/Services/TrustedDestinationReaderTest.php
 */

/**
 * Save a published domain list record.
 *
 * @param  string  $host  The domain it names.
 */
function trustedDomainRecord(string $host): void
{
    if (Collection::findByHandle(RedirectDomainList::COLLECTION) === null) {
        $collection = Collection::make(RedirectDomainList::COLLECTION);
        Assert::assertInstanceOf(StatamicCollection::class, $collection);
        $collection->save();
    }

    $entry = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $entry);
    $entry->collection(RedirectDomainList::COLLECTION);
    $entry->locale('default');
    $entry->published(true);
    $entry->data(['title' => $host, 'host' => $host]);
    $entry->save();
}

it('trusts the application host and its subdomains, each site, and the domain list', function (): void {
    config(['app.url' => 'https://uams.edu']);
    Site::setSites([
        'default' => ['name' => 'Main', 'url' => '/', 'locale' => 'en_US'],
        'health' => ['name' => 'Health', 'url' => 'https://uamshealth.com/', 'locale' => 'en_US'],
    ]);
    trustedDomainRecord('uams.health');

    $trusted = app(TrustedDestinationReader::class)->trusted();

    expect($trusted->allows('https://news.uams.edu/flu'))->toBeTrue()
        ->and($trusted->allows('https://uamshealth.com/clinics'))->toBeTrue()
        ->and($trusted->allows('https://uams.health/flu'))->toBeTrue()
        ->and($trusted->allows('https://example.com/'))->toBeFalse();
});

it('trusts the sites and the domain list when the application URL is not text', function (): void {
    config(['app.url' => null]);
    Site::setSites([
        'default' => ['name' => 'Main', 'url' => 'https://uamshealth.com/', 'locale' => 'en_US'],
    ]);

    $trusted = app(TrustedDestinationReader::class)->trusted();

    expect($trusted->allows('https://uamshealth.com/'))->toBeTrue()
        ->and($trusted->allows('https://uams.edu/'))->toBeFalse()
        ->and($trusted->allows('https://uams.health/'))->toBeFalse();
});
