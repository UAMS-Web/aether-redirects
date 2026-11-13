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
        'creative_services' => false,
        'job_number' => null,
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
        ->and($data['submitted_by'])->toBe('user-1')
        ->and($data['generated_back_half'])->toBeFalse();
});

it('swaps in a generated back-half, keeping its case and every other field', function (): void {
    $redirect = MarketingRedirect::fromInput([
        'source' => '',
        'destination' => 'https://www.uams.edu/flu',
        'response' => '302',
        'host' => 'uams.info',
        'valid_from' => '2026-01-01',
        'valid_until' => '2026-01-31',
        'requester' => 'Communications',
        'notes' => 'Flu season',
    ], 'user-1');

    $generated = $redirect->withGeneratedBackHalf('aB3xY9q');

    expect($redirect->generatedBackHalf)->toBeFalse()
        ->and($generated->generatedBackHalf)->toBeTrue()
        ->and($generated->source)->toBe('/aB3xY9q')
        ->and($generated->destination)->toBe('https://www.uams.edu/flu')
        ->and($generated->response)->toBe('302')
        ->and($generated->window)->toBe($redirect->window)
        ->and($generated->requester)->toBe('Communications')
        ->and($generated->notes)->toBe('Flu season')
        ->and($generated->submittedBy)->toBe('user-1')
        ->and($generated->host)->toBe('uams.info')
        ->and($generated->toDomainData('uams.info')['generated_back_half'])->toBeTrue();
});

it('becomes a short link with a generated code, approved or pending, keeping every other field', function (bool $approved, string $status): void {
    $redirect = MarketingRedirect::fromInput([
        'source' => '',
        'destination' => 'https://example.org/campaign',
        'response' => '302',
        'valid_from' => '2026-01-01',
        'valid_until' => '2026-12-31',
        'requester' => 'Communications',
        'notes' => 'Spring',
        'host' => 'go.uams.edu',
    ], 'user-1');

    $link = $redirect->withShortCode('abc234', $approved);
    $data = $link->toDomainData('record-id');

    expect($redirect->approvedOnCreate)->toBeFalse()
        ->and($redirect->toRow('x')['status'])->toBe('pending')
        ->and($link->approvedOnCreate)->toBe($approved)
        ->and($link->generatedBackHalf)->toBeTrue()
        ->and($link->source)->toBe('/abc234')
        ->and($link->destination)->toBe('https://example.org/campaign')
        ->and($link->response)->toBe('302')
        ->and($link->window)->toBe($redirect->window)
        ->and($link->requester)->toBe('Communications')
        ->and($link->notes)->toBe('Spring')
        ->and($link->submittedBy)->toBe('user-1')
        ->and($link->host)->toBe('go.uams.edu')
        ->and($link->toRow('x')['status'])->toBe($status)
        ->and($data['status'])->toBe($status)
        ->and($data['generated_back_half'])->toBeTrue()
        ->and($data['reviewed_by'])->toBeNull();
})->with([
    'a trusted destination' => [true, 'approved'],
    'any other destination' => [false, 'pending'],
]);

it('keeps a generated back-half pending', function (): void {
    $redirect = MarketingRedirect::fromInput(['source' => '', 'destination' => '/y', 'host' => 'uams.info']);

    expect($redirect->withGeneratedBackHalf('aB3xY9q')->approvedOnCreate)->toBeFalse()
        ->and($redirect->withGeneratedBackHalf('aB3xY9q')->toRow('x')['status'])->toBe('pending');
});

it('leaves the host null when none is given or it is not a domain', function (?string $host): void {
    $redirect = MarketingRedirect::fromInput(['source' => '/x', 'destination' => '/y', 'host' => $host]);

    expect($redirect->host)->toBeNull();
})->with([[null], [''], ['not a host']]);

it('keeps the job number only for a request that involves Creative Services', function (?string $answer, ?string $number, bool $involved, ?string $kept): void {
    $redirect = MarketingRedirect::fromInput([
        'source' => '/vanity',
        'destination' => '/target',
        'creative_services' => $answer,
        'job_number' => $number,
    ]);

    expect($redirect->creativeServices)->toBe($involved)
        ->and($redirect->jobNumber)->toBe($kept)
        ->and($redirect->toRow('row-id')['creative_services'])->toBe($involved)
        ->and($redirect->toRow('row-id')['job_number'])->toBe($kept)
        ->and($redirect->toDomainData('host-id')['job_number'])->toBe($kept);
})->with([
    'yes with a number' => ['yes', ' 12345 ', true, '12345'],
    'yes with a blank number' => ['yes', '   ', true, null],
    'no discards a number' => ['no', '12345', false, null],
    'no answer' => [null, '12345', false, null],
]);

it('carries the Creative Services answer onto a generated back-half', function (): void {
    $redirect = MarketingRedirect::fromInput([
        'source' => '',
        'destination' => '/target',
        'creative_services' => 'yes',
        'job_number' => '12345',
    ])->withGeneratedBackHalf('Ab3Xy9Q');

    expect($redirect->creativeServices)->toBeTrue()
        ->and($redirect->jobNumber)->toBe('12345');
});

it('defaults to a request that does not involve Creative Services', function (): void {
    $redirect = new MarketingRedirect('/vanity', '/target');

    expect($redirect->creativeServices)->toBeFalse()
        ->and($redirect->jobNumber)->toBeNull();
});

it('carries the Creative Services answer onto a short code', function (): void {
    $redirect = MarketingRedirect::fromInput([
        'source' => '',
        'destination' => '/target',
        'creative_services' => 'yes',
        'job_number' => '12345',
    ])->withShortCode('abc234', true);

    expect($redirect->creativeServices)->toBeTrue()
        ->and($redirect->jobNumber)->toBe('12345');
});
