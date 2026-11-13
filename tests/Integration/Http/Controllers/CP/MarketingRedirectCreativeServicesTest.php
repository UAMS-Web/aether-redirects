<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Assert;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

require_once __DIR__.'/../../../../Support/MarketingRedirectDomainHelpers.php';

/**
 * HTTP coverage for the Creative Services question on a redirect request
 * (UAMS-Web/aether-redirects#25): every request answers it, a vanity URL request
 * must answer yes with a job number in the configured format, and the approver sees both.
 *
 * @command  vendor/bin/pest tests/Integration/Http/Controllers/CP/MarketingRedirectCreativeServicesTest.php
 */
// Stache-isolated: every test saves entries, users and roles.
uses(IsolatesStache::class);

beforeEach(function (): void {
    test()->withoutMiddleware(PreventRequestForgery::class);
    test()->withoutVite();

    mrdSetUp();
});

it('refuses a request that does not answer the question', function (mixed $answer, string $message): void {
    $response = mrdSubmit(mrdCreator(), ['creative_services' => $answer, 'job_number' => '12345']);

    $response->assertUnprocessable();
    expect($response->json('errors.creative_services.0'))->toBe($message)
        ->and(mrdEntryCount())->toBe(0);
})->with([
    'missing' => [null, 'This field is required.'],
    'blank' => ['', 'This field is required.'],
    'not yes or no' => ['maybe', 'This is invalid.'],
    'a list' => [['yes'], 'This is invalid.'],
]);

it('refuses a vanity URL request that answers no, and offers a go.uams.edu short code', function (string $host): void {
    $response = mrdSubmit(mrdCreator(), ['host' => $host, 'creative_services' => 'no', 'job_number' => null]);

    $response->assertUnprocessable();
    expect($response->json('errors.creative_services.0'))->toBe('A vanity URL is made with Creative Services. For a link that does not involve them, request a go.uams.edu short code.')
        ->and(mrdEntryCount())->toBe(0);
})->with(['uams.info', 'uams.health', 'uamsbaptist.health']);

it('refuses a vanity URL request that answers yes with no job number', function (?string $number): void {
    $response = mrdSubmit(mrdCreator(), ['creative_services' => 'yes', 'job_number' => $number]);

    $response->assertUnprocessable();
    expect($response->json('errors.job_number.0'))->toBe('Enter the Creative Services job number.')
        ->and(mrdEntryCount())->toBe(0);
})->with([
    'missing' => [null],
    'blank' => ['   '],
]);

it('stores the answer and the job number on a vanity URL request', function (): void {
    $response = mrdSubmit(mrdCreator(), ['creative_services' => 'yes', 'job_number' => ' 98765 ']);

    $response->assertCreated();
    expect($response->json('redirect.creative_services'))->toBeTrue()
        ->and($response->json('redirect.job_number'))->toBe('98765');

    $entry = Entry::find($response->json('redirect.id'));
    Assert::assertInstanceOf(StatamicEntry::class, $entry);

    expect($entry->get('creative_services'))->toBeTrue()
        ->and($entry->get('job_number'))->toBe('98765');
});

it('checks the job number against the configured format, naming it in the refusal', function (): void {
    config([
        'aether-redirects.creative_services.job_number.pattern' => '/^\d{5}$/',
        'aether-redirects.creative_services.job_number.description' => 'five digits',
    ]);

    $refused = mrdSubmit(mrdCreator(), ['creative_services' => 'yes', 'job_number' => 'CS-1']);

    $refused->assertUnprocessable();
    expect($refused->json('errors.job_number.0'))->toBe('The job number must match five digits.')
        ->and(mrdEntryCount())->toBe(0);

    mrdSubmit(mrdCreator(), ['creative_services' => 'yes', 'job_number' => '12345'])->assertCreated();
});

it('accepts a job number of the longest length and refuses one character more', function (): void {
    mrdSubmit(mrdCreator(), ['creative_services' => 'yes', 'job_number' => str_repeat('9', 100)])->assertCreated();

    mrdSubmit(mrdCreator(), ['source' => '/another', 'creative_services' => 'yes', 'job_number' => str_repeat('9', 101)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('job_number');
});

it('refuses a job number that is not text', function (): void {
    $response = mrdSubmit(mrdCreator(), ['creative_services' => 'yes', 'job_number' => ['12345']]);

    $response->assertUnprocessable();

    expect($response->json('errors.job_number.0'))->toBe('Must be a string.');
});

it('accepts a null job number from a request that answers no', function (): void {
    mrdMainSiteGlobal();

    mrdSubmit(mrdCreator(), ['host' => null, 'source' => '/flu', 'destination' => '/clinics/flu', 'creative_services' => 'no', 'job_number' => null])->assertCreated();
});

it('lets a main-site request answer no, and keeps no job number for it', function (): void {
    mrdMainSiteGlobal();

    $response = mrdSubmit(mrdCreator(), ['host' => null, 'source' => '/flu', 'destination' => '/clinics/flu', 'creative_services' => 'no', 'job_number' => '12345']);

    $response->assertCreated();
    expect($response->json('redirect.creative_services'))->toBeFalse()
        ->and($response->json('redirect.job_number'))->toBeNull();
});

it('requires a job number from a main-site request that answers yes', function (): void {
    mrdMainSiteGlobal();

    mrdSubmit(mrdCreator(), ['host' => null, 'source' => '/flu', 'destination' => '/clinics/flu', 'creative_services' => 'yes', 'job_number' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('job_number');
});

it('keeps the answer and job number on a generated back-half request', function (): void {
    $response = mrdSubmit(mrdCreator(), ['source' => '', 'creative_services' => 'yes', 'job_number' => '555']);

    $response->assertCreated();

    expect($response->json('redirect.job_number'))->toBe('555');
});

it('shows the approver the answer and the job number before approving', function (): void {
    mrdMainSiteGlobal();

    mrdSubmit(mrdCreator(), ['source' => '/with-job', 'creative_services' => 'yes', 'job_number' => '24680'])->assertCreated();
    mrdSubmit(mrdCreator(), ['host' => null, 'source' => '/without-job', 'destination' => '/clinics/flu', 'creative_services' => 'no'])->assertCreated();

    test()->actingAs(mrdApprover());

    test()->get(mrdRoute('marketing-redirects.review'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('redirects', 2)
            // The main-site request is listed before the domain request
            ->where('redirects.0.creative_services', false)
            ->where('redirects.0.job_number', null)
            ->where('redirects.1.creative_services', true)
            ->where('redirects.1.job_number', '24680'));
});

it('treats a stored row from before the question as not involving Creative Services', function (): void {
    mrdSeed(['host' => 'uams.info', 'source' => '/old', 'target_url' => 'https://www.uams.edu/old', 'status' => 'pending']);

    test()->actingAs(mrdApprover());

    test()->get(mrdRoute('marketing-redirects.review'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('redirects.0.creative_services', false)
            ->where('redirects.0.job_number', null));
});

it('tells the create screen the job number format in words', function (): void {
    config(['aether-redirects.creative_services.job_number.description' => 'five digits']);

    test()->actingAs(mrdCreator());

    test()->get(mrdRoute('marketing-redirects.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('jobNumberDescription', 'five digits'));
});
