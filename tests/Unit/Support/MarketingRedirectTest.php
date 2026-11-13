<?php

declare(strict_types=1);

use UAMSWeb\Aether\Redirects\Support\MarketingRedirect;

/**
 * Unit tests for the {@see MarketingRedirect} typed model — building it from
 * validated input and rendering the stored replicator row the serving tag reads
 * (UAMS-Web/uams-statamic-archive#1355).
 *
 * @command  vendor/bin/pest tests/Unit/Support/MarketingRedirectTest.php
 */
it('builds from validated input, normalizing source, destination and metadata', function (): void {
    $redirect = MarketingRedirect::fromInput([
        'source' => 'Flu-Shot/',
        'destination' => '  /clinics/flu  ',
        'response' => '302',
        'valid_from' => '2026-01-10',
        'valid_until' => '2026-01-20',
        'requester' => '  Marketing  ',
        'notes' => '  vanity URL for the flu campaign ',
    ]);

    expect($redirect->source)->toBe('/Flu-Shot')
        ->and($redirect->destination)->toBe('/clinics/flu')
        ->and($redirect->response)->toBe('302')
        ->and($redirect->requester)->toBe('Marketing')
        ->and($redirect->notes)->toBe('vanity URL for the flu campaign')
        ->and($redirect->window->from?->toDateString())->toBe('2026-01-10')
        ->and($redirect->window->until?->toDateString())->toBe('2026-01-20');
});

it('defaults the response to 301 and collapses blank optional fields to null', function (): void {
    $redirect = MarketingRedirect::fromInput([
        'source' => '/vanity',
        'destination' => '/target',
        'valid_from' => '',
        'valid_until' => null,
        'requester' => '   ',
        'notes' => '',
    ]);

    expect($redirect->response)->toBe('301')
        ->and($redirect->requester)->toBeNull()
        ->and($redirect->notes)->toBeNull()
        ->and($redirect->window->from)->toBeNull()
        ->and($redirect->window->until)->toBeNull();
});

it('renders a replicator row matching the keys the serving tag reads', function (): void {
    $redirect = MarketingRedirect::fromInput([
        'source' => '/vanity',
        'destination' => 'https://example.test/deep',
        'response' => '301',
        'valid_from' => '2026-03-01',
        'valid_until' => '2026-03-31',
        'requester' => 'Comms',
        'notes' => 'spring push',
    ]);

    expect($redirect->toRow('row-id-1'))->toBe([
        'id' => 'row-id-1',
        'type' => 'redirect',
        'enabled' => true,
        'status' => 'pending',
        'source' => '/vanity',
        'use_regex' => false,
        'target_type' => 'url',
        'target_url' => 'https://example.test/deep',
        'response' => '301',
        'order' => 30,
        'valid_from' => '2026-03-01',
        'valid_until' => '2026-03-31',
        'requester' => 'Comms',
        'notes' => 'spring push',
        'submitted_by' => null,
        'reviewed_by' => null,
        'rejection_reason' => null,
    ]);
});

it('starts a utility-created redirect pending and records the submitter', function (): void {
    $redirect = MarketingRedirect::fromInput([
        'source' => '/vanity',
        'destination' => '/target',
    ], 'user-42');

    $row = $redirect->toRow('row-id-3');

    // A created redirect enters the approval queue and remembers its creator so
    // the approval slice can forbid self-approval (UAMS-Web/uams-statamic-archive#1357).
    expect($redirect->submittedBy)->toBe('user-42')
        ->and($row['status'])->toBe('pending')
        ->and($row['submitted_by'])->toBe('user-42')
        ->and($row['reviewed_by'])->toBeNull()
        ->and($row['rejection_reason'])->toBeNull()
        ->and($row['enabled'])->toBeTrue();
});

it('collapses a blank submitter id to null', function (): void {
    $redirect = MarketingRedirect::fromInput([
        'source' => '/vanity',
        'destination' => '/target',
    ], '   ');

    expect($redirect->submittedBy)->toBeNull()
        ->and($redirect->toRow('row-id-4')['submitted_by'])->toBeNull();
});

it('renders null validity dates when the window is open', function (): void {
    $redirect = MarketingRedirect::fromInput([
        'source' => '/vanity',
        'destination' => '/target',
    ]);

    $row = $redirect->toRow('row-id-2');

    expect($row['valid_from'])->toBeNull()
        ->and($row['valid_until'])->toBeNull()
        ->and($row['enabled'])->toBeTrue()
        ->and($row['use_regex'])->toBeFalse()
        ->and($row['type'])->toBe('redirect');
});

it('offers exactly the 301 and 302 responses to the host application', function (): void {
    // The host validates the utility's `response` field against this list.
    expect(MarketingRedirect::ALLOWED_RESPONSES)->toBe(['301', '302']);
});

it('renders the data of a redirect domain entry, without the row id and type', function (): void {
    $redirect = MarketingRedirect::fromInput([
        'source' => 'flu/',
        'destination' => 'https://www.uams.edu/flu',
        'host' => 'UAMS.info.',
        'valid_until' => '2026-01-31',
    ], 'user-1');

    $data = $redirect->toDomainData('uams.info');

    expect($redirect->host)->toBe('uams.info')
        ->and($data)->not->toHaveKeys(['id', 'type'])
        ->and($data['host'])->toBe('uams.info')
        ->and($data['whole_domain'])->toBeFalse()
        ->and($data['status'])->toBe('pending')
        ->and($data['source'])->toBe('/flu')
        ->and($data['target_url'])->toBe('https://www.uams.edu/flu')
        ->and($data['valid_until'])->toBe('2026-01-31')
        ->and($data['submitted_by'])->toBe('user-1');
});

it('leaves the host null when none is given or it is not a domain', function (?string $host): void {
    $redirect = MarketingRedirect::fromInput(['source' => '/x', 'destination' => '/y', 'host' => $host]);

    expect($redirect->host)->toBeNull();
})->with([[null], [''], ['not a host']]);
