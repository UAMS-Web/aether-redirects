<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/TaggedUrlChoiceHelpers.php';

use Illuminate\Validation\ValidationException;
use UAMSWeb\Aether\Redirects\Services\TaggedUrlChoiceValidator;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlLookup;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlRequest;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

uses(IsolatesStache::class);

covers(TaggedUrlChoiceValidator::class);

/**
 * Integration tests for {@see TaggedUrlChoiceValidator}: a submission's choices checked
 * against the site's cascade and parameters (UAMS-Web/aether-redirects#119).
 *
 * @command  vendor/bin/pest tests/Integration/Services/TaggedUrlChoiceValidatorTest.php
 */
beforeEach(function (): void {
    tcSetUp();
});

/**
 * Validate a submission against the site.
 *
 * @param  array<mixed>  $input  The submission.
 */
function tcvValidate(array $input, bool $suppress = false): TaggedUrlRequest
{
    return app(TaggedUrlChoiceValidator::class)->validate('https://www.uams.edu/flu', $suppress, $input);
}

/**
 * The messages of every field a submission is refused for.
 *
 * @param  array<mixed>  $input  The submission.
 * @return array<string, list<string>>
 */
function tcvErrors(array $input): array
{
    try {
        tcvValidate($input);
    } catch (ValidationException $validationException) {
        /** @var array<string, list<string>> $errors */
        $errors = $validationException->errors();

        return $errors;
    }

    return [];
}

it('builds the request from the choices that passed', function (): void {
    $request = tcvValidate([...tcChoices(), 'utm_campaign' => ' flu-2026 '], suppress: true);

    expect($request->destination)->toBe('https://www.uams.edu/flu')
        ->and($request->channel)->toBe('ch-social')
        ->and($request->steps)->toBe(['source' => 'src-facebook', 'medium' => 'med-feed'])
        ->and($request->lists)->toBe(['audiences' => 'aud-staff'])
        ->and($request->custom)->toBe(['audiences' => 'Extra'])
        ->and($request->fields)->toBe(['utm_campaign' => 'flu-2026', 'vanity_path' => '/flu'])
        ->and($request->suppressUtm)->toBeTrue();
});

it('reads a request field after one that was not submitted', function (): void {
    expect(tcvValidate(['utm_campaign' => null, 'vanity_path' => '/flu'])->fields)->toBe(['vanity_path' => '/flu']);
});

it('trims a request field and leaves out one that is only blanks', function (): void {
    expect(tcvValidate(['utm_campaign' => '  flu  ', 'vanity_path' => '   '])->fields)->toBe(['utm_campaign' => 'flu']);
});

it('means no choice by a blank channel, step, list choice or custom value, whatever blank is', function (array $input): void {
    $request = tcvValidate($input);

    expect($request->channel)->toBeNull()
        ->and($request->steps)->toBe([])
        ->and($request->lists)->toBe([])
        ->and($request->custom)->toBe([]);
})->with([
    'nothing submitted' => [[]],
    'null' => [['channel' => null, 'steps' => null, 'lists' => null, 'custom_values' => null]],
    'empty text' => [['channel' => '', 'steps' => ['source' => ''], 'lists' => ['audiences' => ''], 'custom_values' => ['audiences' => '']]],
    'blanks' => [['channel' => '  ', 'steps' => ['source' => '  '], 'lists' => ['audiences' => '  '], 'custom_values' => ['audiences' => '  ']]],
    'empty lists' => [['channel' => [], 'steps' => ['source' => []], 'lists' => ['audiences' => []], 'custom_values' => []]],
]);

it('keeps looking after a blank list choice', function (): void {
    expect(tcvValidate(['lists' => ['audiences' => '', 'regions' => 'reg-north']])->lists)->toBe(['regions' => 'reg-north']);
});

it('keeps looking after a step that was not chosen', function (): void {
    // With no source chosen, the medium list is the generic one
    expect(tcvValidate(['channel' => 'ch-social', 'steps' => ['source' => '', 'medium' => 'med-other']])->steps)->toBe(['medium' => 'med-other']);
});

it('reports every custom value nothing reads, not only the first', function (): void {
    expect(array_keys(tcvErrors(['custom_values' => ['one' => 'a', 'two' => 'b', 'audiences' => 'ok']])))->toBe(['custom_values.one', 'custom_values.two']);
});

it('reports every step that is not in the cascade, and every list nothing reads', function (): void {
    expect(array_keys(tcvErrors(['steps' => ['one' => 'a', 'two' => 'b'], 'lists' => ['social-sources' => 'src-facebook', 'generic-media' => 'med-other']])))
        ->toBe(['steps.one', 'steps.two', 'lists.social-sources', 'lists.generic-media']);
});

it('bounds a request field and a custom value at 255 characters, trimmed', function (): void {
    $edge = str_repeat('a', 255);

    $request = tcvValidate(['utm_campaign' => null, 'vanity_path' => ' '.$edge.' ', 'custom_values' => ['source' => $edge]]);

    expect($request->fields)->toBe(['vanity_path' => $edge])
        ->and($request->custom)->toBe(['source' => $edge])
        ->and(tcvErrors(['vanity_path' => $edge.'a']))->toBe(['vanity_path' => ['This may not be longer than 255 characters.']])
        ->and(tcvErrors(['custom_values' => ['source' => $edge.'a']]))->toBe(['custom_values.source' => ['This may not be longer than 255 characters.']]);
});

it('says why a choice or a value is refused', function (): void {
    expect(tcvErrors(['vanity_path' => ['x']]))->toBe(['vanity_path' => ['This must be text.']])
        ->and(tcvErrors(['utm_id' => '42']))->toBe(['utm_id' => ['No tagged URL parameter reads this field.']])
        ->and(tcvErrors(['channel' => 'src-facebook']))->toBe(['channel' => ['The channel is not one this site offers.']])
        ->and(tcvErrors(['steps' => 'x']))->toBe(['steps' => ['This must be a list of choices.']])
        ->and(tcvErrors(['steps' => ['source' => 'nope']]))->toBe(['steps.source' => ['This choice is not offered at this step.']])
        ->and(tcvErrors(['steps' => ['colour' => 'x']]))->toBe(['steps.colour' => ['This is not a step of the cascade.']])
        ->and(tcvErrors(['lists' => ['social-sources' => 'src-facebook']]))->toBe(['lists.social-sources' => ['No tagged URL parameter reads this list.']])
        ->and(tcvErrors(['lists' => ['audiences' => 'src-facebook']]))->toBe(['lists.audiences' => ['This choice is not in the list.']])
        ->and(tcvErrors(['custom_values' => ['x' => 'a']]))->toBe(['custom_values.x' => ['No tagged URL parameter reads this value.']]);
});

it('compares ids as exact text, so a number that reads as another id is not that id', function (): void {
    tcEntry(TaggedUrlLookup::LISTS_COLLECTION, '10', ['title' => 'Ten']);
    tcEntry(TaggedUrlLookup::OPTIONS_COLLECTION, 'opt-ten', ['title' => 'Ten', 'value' => 'ten', 'list' => ['10']]);
    tcParameters([...tcDefaultParameters(), 'custom_parameters' => [['name' => 'ten', 'enabled' => true, 'order' => 1, 'source_type' => 'list', 'list' => ['10']]]]);

    expect(tcvValidate(['lists' => ['10' => 'opt-ten']])->lists)->toBe([10 => 'opt-ten'])
        ->and(array_keys(tcvErrors(['lists' => ['1e1' => 'opt-ten']])))->toBe(['lists.1e1']);
});
