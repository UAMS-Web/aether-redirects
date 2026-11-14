<?php

declare(strict_types=1);

use UAMSWeb\Aether\Redirects\Support\LookupCatalog;
use UAMSWeb\Aether\Redirects\Support\LookupOption;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlComposer;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlParameter;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlRequest;

covers(TaggedUrlComposer::class);

/**
 * The worked example on the wiki page `docs/wiki/Setting-up-tagged-URL-lists.md`
 * (UAMS-Web/aether-redirects#121), built from the same lists and parameters, so the page
 * and the code cannot drift apart.
 *
 * @command  vendor/bin/pest tests/Unit/Support/TaggedUrlWikiExampleTest.php
 */
function wikiExampleComposer(): TaggedUrlComposer
{
    return new TaggedUrlComposer(
        new LookupCatalog([], [
            new LookupOption('ch-social', 'channels', 'Social', 'social', pointers: ['source' => 'social-sources']),
            new LookupOption('src-facebook', 'social-sources', 'Facebook', 'Facebook', pointers: ['medium' => 'facebook-media']),
            new LookupOption('med-paid', 'facebook-media', 'Paid social', 'paid_social'),
            new LookupOption('med-other', 'generic-media', 'Other', 'other'),
        ]),
        'channels',
        ['medium' => 'generic-media'],
        TaggedUrlParameter::fromGlobal(['utm_source', 'utm_medium', 'utm_campaign'], []),
    );
}

it('issues the URL the wiki page shows for its worked example', function (): void {
    $request = new TaggedUrlRequest(
        'https://www.uams.edu/clinics/flu',
        channel: 'ch-social',
        steps: ['source' => 'src-facebook', 'medium' => 'med-paid'],
        custom: ['source' => 'Spring Push'],
        fields: ['utm_campaign' => 'flu-2026'],
    );

    expect(wikiExampleComposer()->compose($request))
        ->toBe('https://www.uams.edu/clinics/flu?utm_source=facebook_spring%20push&utm_medium=paid_social&utm_campaign=flu-2026');
});

it('offers the lists the wiki page says each step offers', function (): void {
    $composer = wikiExampleComposer();
    $social = new TaggedUrlRequest('https://www.uams.edu/', channel: 'ch-social');
    $facebook = new TaggedUrlRequest('https://www.uams.edu/', channel: 'ch-social', steps: ['source' => 'src-facebook']);

    expect($composer->listFor('source', $social))->toBe('social-sources')
        ->and($composer->listFor('medium', $social))->toBe('generic-media')
        ->and($composer->listFor('medium', $facebook))->toBe('facebook-media');
});
