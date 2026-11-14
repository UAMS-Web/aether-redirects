<?php

declare(strict_types=1);

use UAMSWeb\Aether\Redirects\Support\LookupCatalog;
use UAMSWeb\Aether\Redirects\Support\LookupOption;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlComposer;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlParameter;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlRequest;

covers(TaggedUrlComposer::class, TaggedUrlRequest::class);

/**
 * Unit tests for {@see TaggedUrlComposer}: walking the cascade and composing a
 * request's tagged URL (UAMS-Web/aether-redirects#26).
 *
 * The worked example: a Social channel leads to the social source list, Facebook
 * there names its own medium list, and every other step falls back to its generic list.
 *
 * @covers \UAMSWeb\Aether\Redirects\Support\TaggedUrlComposer
 * @covers \UAMSWeb\Aether\Redirects\Support\TaggedUrlRequest
 *
 * @command  vendor/bin/pest tests/Unit/Support/TaggedUrlComposerTest.php
 */

/**
 * The worked example's catalog.
 */
function taggedExampleCatalog(): LookupCatalog
{
    return new LookupCatalog([], [
        new LookupOption('ch-social', 'channels', 'Social', 'social', pointers: ['source' => 'social-sources', 'placement' => 'social-placements']),
        new LookupOption('ch-print', 'channels', 'Print', 'print', pointers: ['source' => 'print-sources']),
        new LookupOption('src-facebook', 'social-sources', 'Facebook', 'Facebook', pointers: ['medium' => 'facebook-media']),
        new LookupOption('src-linkedin', 'social-sources', 'LinkedIn', 'linkedin'),
        new LookupOption('src-poster', 'print-sources', 'Poster', 'poster'),
        new LookupOption('med-paid', 'facebook-media', 'Paid social', 'paid_social', pointers: ['placement' => 'feed-placements']),
        new LookupOption('med-generic', 'generic-media', 'Other', 'other'),
        new LookupOption('pl-feed', 'feed-placements', 'Feed', 'feed'),
        new LookupOption('pl-story', 'social-placements', 'Story', 'story'),
        new LookupOption('pl-odd', 'feed-placements', 'Odd', 'odd', pointers: ['medium' => 'odd-media', 'source' => 'odd-sources']),
        new LookupOption('org-com', 'org-units', 'Communications', 'com'),
        new LookupOption('retired', 'social-sources', 'Old', 'myspace', active: false),
    ]);
}

/**
 * The worked example's composer, with the given parameters.
 *
 * @param  list<TaggedUrlParameter>  $parameters  The parameters a URL carries.
 * @param  string|null  $channelList  The channel list's id.
 */
function taggedExampleComposer(array $parameters = [], ?string $channelList = 'channels'): TaggedUrlComposer
{
    return new TaggedUrlComposer(taggedExampleCatalog(), $channelList, [
        'source' => 'generic-sources',
        'medium' => 'generic-media',
        'placement' => 'generic-placements',
    ], $parameters);
}

/**
 * The ids of a list of options, in order.
 *
 * @param  list<LookupOption>  $options  The options.
 * @return list<string>
 */
function taggedOptionIds(array $options): array
{
    return array_map(static fn (LookupOption $option): string => $option->id, $options);
}

it('offers the channel list first, and nothing when the site names none', function (): void {
    expect(taggedOptionIds(taggedExampleComposer()->channelOptions()))->toBe(['ch-social', 'ch-print'])
        ->and(taggedExampleComposer(channelList: null)->channelOptions())->toBe([]);
});

it('narrows each step by the choices before it', function (): void {
    $composer = taggedExampleComposer();
    $social = new TaggedUrlRequest('https://uams.edu/', channel: 'ch-social');
    $print = new TaggedUrlRequest('https://uams.edu/', channel: 'ch-print');
    $facebook = new TaggedUrlRequest('https://uams.edu/', channel: 'ch-social', steps: ['source' => 'src-facebook']);

    expect(taggedOptionIds($composer->optionsFor('source', $social)))->toBe(['src-facebook', 'src-linkedin'])
        ->and(taggedOptionIds($composer->optionsFor('source', $print)))->toBe(['src-poster'])
        ->and($composer->listFor('medium', $social))->toBe('generic-media')
        ->and($composer->listFor('medium', $facebook))->toBe('facebook-media')
        ->and(taggedOptionIds($composer->optionsFor('medium', $facebook)))->toBe(['med-paid']);
});

it('uses the deepest choice for a step, then a shallower one, then the generic list', function (): void {
    $composer = taggedExampleComposer();
    $paid = new TaggedUrlRequest('https://uams.edu/', channel: 'ch-social', steps: ['source' => 'src-facebook', 'medium' => 'med-paid']);
    $linkedin = new TaggedUrlRequest('https://uams.edu/', channel: 'ch-social', steps: ['source' => 'src-linkedin']);
    $poster = new TaggedUrlRequest('https://uams.edu/', channel: 'ch-print', steps: ['source' => 'src-poster']);

    expect($composer->listFor('placement', $paid))->toBe('feed-placements')
        ->and($composer->listFor('placement', $linkedin))->toBe('social-placements')
        ->and($composer->listFor('placement', $poster))->toBe('generic-placements')
        ->and($composer->listFor('term', $poster))->toBeNull()
        ->and($composer->optionsFor('term', $poster))->toBe([]);
});

it('ignores choices at the step being offered and later steps', function (): void {
    $request = new TaggedUrlRequest('https://uams.edu/', channel: 'ch-print', steps: [
        'source' => 'src-poster',
        'medium' => 'med-paid',
    ]);

    $later = new TaggedUrlRequest('https://uams.edu/', channel: 'ch-print', steps: ['placement' => 'pl-odd']);

    // `med-paid` names a placement list, but it is the medium step's own choice, and
    // `pl-odd` at the later placement step names medium and source lists.
    expect(taggedExampleComposer()->listFor('medium', $request))->toBe('generic-media')
        ->and(taggedExampleComposer()->listFor('source', $request))->toBe('print-sources')
        ->and(taggedExampleComposer()->listFor('medium', $later))->toBe('generic-media')
        ->and(taggedExampleComposer()->listFor('source', $later))->toBe('print-sources');
});

it('skips a chosen option that no longer exists', function (): void {
    $request = new TaggedUrlRequest('https://uams.edu/', channel: 'gone', steps: ['source' => 'src-facebook']);

    expect(taggedExampleComposer()->listFor('source', $request))->toBe('generic-sources')
        ->and(taggedExampleComposer()->listFor('medium', $request))->toBe('facebook-media');
});

it('composes the tagged URL from steps, lists, request fields and fixed values', function (): void {
    $composer = taggedExampleComposer([
        ...TaggedUrlParameter::fromGlobal(['utm_source', 'utm_medium', 'utm_campaign'], []),
        new TaggedUrlParameter('org_unit', TaggedUrlParameter::FROM_LIST, 'org-units'),
        new TaggedUrlParameter('placement', TaggedUrlParameter::FROM_STEP, 'placement'),
        new TaggedUrlParameter('qr', TaggedUrlParameter::FROM_FIXED_VALUE, '1'),
        new TaggedUrlParameter('vanity', TaggedUrlParameter::FROM_REQUEST_FIELD, 'vanity_path'),
    ]);

    $request = new TaggedUrlRequest(
        'https://uams.edu/flu',
        channel: 'ch-social',
        steps: ['source' => 'src-facebook', 'medium' => 'med-paid', 'placement' => 'pl-feed'],
        lists: ['org-units' => 'org-com'],
        custom: ['source' => 'Spring Push', 'org-units' => 'Web'],
        fields: ['utm_campaign' => '  Flu & COVID 2026 ', 'vanity_path' => 'flu'],
    );

    expect($composer->compose($request))->toBe(
        'https://uams.edu/flu?utm_source=facebook_spring%20push&utm_medium=paid_social'
        .'&utm_campaign=Flu%20%26%20COVID%202026&org_unit=com_web&placement=feed&qr=1&vanity=flu'
    );
});

it("leaves out a parameter the request gives no value for, and keeps a retired choice's value", function (): void {
    $composer = taggedExampleComposer([
        ...TaggedUrlParameter::fromGlobal(['utm_source', 'utm_medium', 'utm_term', 'utm_campaign'], []),
        new TaggedUrlParameter('org_unit', TaggedUrlParameter::FROM_LIST, 'org-units'),
    ]);

    $request = new TaggedUrlRequest('https://uams.edu/', channel: 'ch-social', steps: ['source' => 'retired', 'medium' => 'gone']);

    expect($composer->compose($request))->toBe('https://uams.edu/?utm_source=myspace');
});

it('leaves every utm_* parameter out when the request suppresses them', function (): void {
    $composer = taggedExampleComposer([
        ...TaggedUrlParameter::fromGlobal(['utm_source'], []),
        new TaggedUrlParameter('org_unit', TaggedUrlParameter::FROM_LIST, 'org-units'),
    ]);

    $request = new TaggedUrlRequest('https://uams.edu/', steps: ['source' => 'src-linkedin'], lists: ['org-units' => 'org-com'], suppressUtm: true);

    expect($composer->compose($request))->toBe('https://uams.edu/?org_unit=com');
});

it("carries a request's values as given", function (): void {
    $request = new TaggedUrlRequest('https://uams.edu/', 'c', ['source' => 's'], ['l' => 'o'], ['source' => 'x'], ['f' => 'v'], true);

    expect([$request->destination, $request->channel, $request->steps, $request->lists, $request->custom, $request->fields, $request->suppressUtm])
        ->toBe(['https://uams.edu/', 'c', ['source' => 's'], ['l' => 'o'], ['source' => 'x'], ['f' => 'v'], true])
        ->and((new TaggedUrlRequest('https://uams.edu/'))->suppressUtm)->toBeFalse();
});

it('lists the cascade steps an enabled parameter reads, in cascade order', function (): void {
    $composer = taggedExampleComposer([
        new TaggedUrlParameter('utm_medium', TaggedUrlParameter::FROM_STEP, 'medium'),
        new TaggedUrlParameter('utm_source', TaggedUrlParameter::FROM_STEP, 'source'),
        new TaggedUrlParameter('again', TaggedUrlParameter::FROM_STEP, 'source'),
        new TaggedUrlParameter('utm_campaign', TaggedUrlParameter::FROM_REQUEST_FIELD, 'utm_campaign'),
        new TaggedUrlParameter('org_unit', TaggedUrlParameter::FROM_LIST, 'medium'),
        new TaggedUrlParameter('qr', TaggedUrlParameter::FROM_FIXED_VALUE, 'placement'),
    ]);

    expect($composer->stepsInUse())->toBe(['source', 'medium'])
        ->and(taggedExampleComposer()->stepsInUse())->toBe([]);
});

it('lists the list-sourced parameters and the request fields the parameters read', function (): void {
    $composer = taggedExampleComposer([
        new TaggedUrlParameter('utm_campaign', TaggedUrlParameter::FROM_REQUEST_FIELD, 'utm_campaign'),
        new TaggedUrlParameter('org_unit', TaggedUrlParameter::FROM_LIST, 'org-units'),
        new TaggedUrlParameter('vanity', TaggedUrlParameter::FROM_REQUEST_FIELD, 'vanity_path'),
        new TaggedUrlParameter('campaign_again', TaggedUrlParameter::FROM_REQUEST_FIELD, 'utm_campaign'),
        new TaggedUrlParameter('utm_source', TaggedUrlParameter::FROM_STEP, 'source'),
    ]);

    expect(array_map(static fn (TaggedUrlParameter $parameter): string => $parameter->name, $composer->listParameters()))->toBe(['org_unit'])
        ->and($composer->requestFields())->toBe(['utm_campaign', 'vanity_path']);
});

it('offers the active options of any list, outside the cascade', function (): void {
    expect(taggedOptionIds(taggedExampleComposer()->optionsOfList('social-sources')))->toBe(['src-facebook', 'src-linkedin'])
        ->and(taggedExampleComposer()->optionsOfList('no-such-list'))->toBe([]);
});
