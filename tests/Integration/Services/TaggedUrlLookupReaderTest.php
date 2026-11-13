<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Globals\GlobalSet as StatamicGlobalSet;
use Statamic\Globals\Variables;
use Statamic\Sites\Site as StatamicSite;
use UAMSWeb\Aether\Redirects\Services\TaggedUrlLookupReader;
use UAMSWeb\Aether\Redirects\Support\LookupOption;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlLookup;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlRequest;
use UAMSWeb\Aether\Redirects\Tests\Support\IsolatesStache;

// Stache-isolated: each test saves its own collections, entries and global set.
uses(IsolatesStache::class);

covers(TaggedUrlLookupReader::class);

/**
 * Integration tests for {@see TaggedUrlLookupReader}: the host's lookup entries and
 * parameters global read into the cascade and URL composer (UAMS-Web/aether-redirects#26).
 *
 * @command  vendor/bin/pest tests/Integration/Services/TaggedUrlLookupReaderTest.php
 */

/**
 * Save the host's two lookup collections.
 */
function taggedLookupCollections(): void
{
    foreach ([TaggedUrlLookup::LISTS_COLLECTION, TaggedUrlLookup::OPTIONS_COLLECTION] as $handle) {
        $collection = Collection::make($handle);
        Assert::assertInstanceOf(StatamicCollection::class, $collection);
        $collection->save();
    }
}

/**
 * Save a lookup entry.
 *
 * @param  string  $collection  The collection handle.
 * @param  string  $id  The entry id.
 * @param  array<string, mixed>  $data  The entry's fields.
 * @param  bool  $published  Whether it is published.
 */
function taggedLookupEntry(string $collection, string $id, array $data, bool $published = true): void
{
    $entry = Entry::make();
    Assert::assertInstanceOf(StatamicEntry::class, $entry);

    $entry->id($id);
    $entry->collection($collection);
    $entry->locale('default');
    $entry->published($published);
    $entry->data($data);
    $entry->save();
}

/**
 * Save the parameters global with the default site's values.
 *
 * @param  array<string, mixed>  $data  The global's values.
 */
function taggedParametersGlobal(array $data): void
{
    $set = GlobalSet::make(TaggedUrlLookup::PARAMETERS_GLOBAL);
    Assert::assertInstanceOf(StatamicGlobalSet::class, $set);
    $set->title('Tagged URL parameters');
    $set->save();

    $site = Site::default();
    Assert::assertInstanceOf(StatamicSite::class, $site);
    $variables = $set->makeLocalization($site->handle());
    Assert::assertInstanceOf(Variables::class, $variables);
    $variables->data($data);
    $variables->save();
}

/**
 * The worked example, saved as the host would hold it.
 */
function taggedLookupExample(): void
{
    taggedLookupCollections();

    taggedLookupEntry(TaggedUrlLookup::LISTS_COLLECTION, 'channels', ['title' => 'Channels']);
    taggedLookupEntry(TaggedUrlLookup::LISTS_COLLECTION, 'social-sources', ['title' => 'Social sources', 'members' => 'extra-sources']);
    taggedLookupEntry(TaggedUrlLookup::LISTS_COLLECTION, 'extra-sources', ['title' => 'Extra sources']);
    taggedLookupEntry(TaggedUrlLookup::LISTS_COLLECTION, 'generic-media', ['title' => 'Generic media']);
    taggedLookupEntry(TaggedUrlLookup::LISTS_COLLECTION, 'all-media', ['title' => 'All media', 'members' => [null, 'generic-media', 7]]);

    taggedLookupEntry(TaggedUrlLookup::OPTIONS_COLLECTION, 'ch-social', ['title' => 'Social', 'value' => 'social', 'list' => ['channels'], 'source' => ['social-sources']]);
    taggedLookupEntry(TaggedUrlLookup::OPTIONS_COLLECTION, 'src-facebook', ['title' => 'Facebook', 'value' => 'facebook', 'list' => 'social-sources', 'order' => 2, 'medium' => 'facebook-media']);
    taggedLookupEntry(TaggedUrlLookup::OPTIONS_COLLECTION, 'src-linkedin', ['title' => 'LinkedIn', 'value' => 'linkedin', 'list' => ['social-sources'], 'order' => '1']);
    taggedLookupEntry(TaggedUrlLookup::OPTIONS_COLLECTION, 'src-old', ['title' => 'Old', 'value' => 'myspace', 'list' => ['social-sources'], 'active' => false]);
    taggedLookupEntry(TaggedUrlLookup::OPTIONS_COLLECTION, 'src-tiktok', ['title' => 'TikTok', 'value' => 'tiktok', 'list' => ['extra-sources']]);
    taggedLookupEntry(TaggedUrlLookup::OPTIONS_COLLECTION, 'src-draft', ['title' => 'Draft', 'value' => 'draft', 'list' => ['social-sources']], published: false);
    taggedLookupEntry(TaggedUrlLookup::OPTIONS_COLLECTION, 'med-other', ['title' => 'Other', 'value' => 'other', 'list' => ['generic-media']]);

    taggedParametersGlobal([
        TaggedUrlLookup::CHANNEL_LIST_FIELD => ['channels'],
        'fallback_medium' => ['generic-media'],
        'fallback_placement' => [],
        'standard_parameters' => ['utm_source', 'utm_medium', 'utm_campaign'],
        'custom_parameters' => [
            ['name' => 'qr', 'enabled' => true, 'order' => 1, 'source_type' => 'fixed_value', 'fixed_value' => '1'],
        ],
    ]);
}

/**
 * The ids of a list of options, in order.
 *
 * @param  list<LookupOption>  $options  The options.
 * @return list<string>
 */
function taggedReadIds(array $options): array
{
    return array_map(static fn (LookupOption $option): string => $option->id, $options);
}

it("reads the channel list, composed lists and each step's generic list from the host", function (): void {
    taggedLookupExample();

    $composer = app(TaggedUrlLookupReader::class)->composer();
    $social = new TaggedUrlRequest('https://uams.edu/', channel: 'ch-social');

    expect(taggedReadIds($composer->channelOptions()))->toBe(['ch-social'])
        ->and(taggedReadIds($composer->optionsFor('source', $social)))->toBe(['src-linkedin', 'src-facebook', 'src-tiktok'])
        ->and($composer->listFor('medium', $social))->toBe('generic-media')
        ->and($composer->listFor('placement', $social))->toBeNull()
        ->and(taggedReadIds(app(TaggedUrlLookupReader::class)->catalog()->options('all-media')))->toBe(['med-other'])
        ->and($composer->listFor('medium', new TaggedUrlRequest('https://uams.edu/', channel: 'ch-social', steps: ['source' => 'src-facebook'])))
        ->toBe('facebook-media');
});

it('composes the URL from the parameters the global defines', function (): void {
    taggedLookupExample();

    $request = new TaggedUrlRequest(
        'https://uams.edu/flu',
        channel: 'ch-social',
        steps: ['source' => 'src-facebook', 'medium' => 'med-other'],
        fields: ['utm_campaign' => 'flu-2026'],
    );

    expect(app(TaggedUrlLookupReader::class)->composer()->compose($request))
        ->toBe('https://uams.edu/flu?utm_source=facebook&utm_medium=other&utm_campaign=flu-2026&qr=1');
});

it("reads an option's fields, with defaults for what is not stored", function (): void {
    taggedLookupExample();
    taggedLookupEntry(TaggedUrlLookup::OPTIONS_COLLECTION, 'bare', ['title' => 7, 'value' => ['not text'], 'order' => 'first']);

    $catalog = app(TaggedUrlLookupReader::class)->catalog();
    $facebook = $catalog->option('src-facebook');
    $old = $catalog->option('src-old');

    expect($facebook)->toEqual(new LookupOption('src-facebook', 'social-sources', 'Facebook', 'facebook', true, 2, ['medium' => 'facebook-media']))
        ->and($old?->active)->toBeFalse()
        ->and($catalog->option('src-draft'))->toBeNull()
        ->and($catalog->option('bare'))->toBeNull()
        ->and($catalog->option('ch-social')?->pointers)->toBe(['source' => 'social-sources']);
});

it('reads an option that names no list as belonging to none, with its label and order defaults', function (): void {
    taggedLookupCollections();
    taggedLookupEntry(TaggedUrlLookup::LISTS_COLLECTION, 'l', ['title' => 'L']);
    taggedLookupEntry(TaggedUrlLookup::OPTIONS_COLLECTION, 'num', ['title' => 7, 'value' => 42, 'list' => ['l']]);
    taggedLookupEntry(TaggedUrlLookup::OPTIONS_COLLECTION, 'odd', ['value' => ['x'], 'list' => ['l'], 'order' => 'first']);

    $options = app(TaggedUrlLookupReader::class)->catalog()->options('l');

    expect($options)->toEqual([
        new LookupOption('num', 'l', '7', '42'),
        new LookupOption('odd', 'l', '', '', true, 0),
    ]);
});

it('reads everything as empty when the host defines no lookup data', function (): void {
    $composer = app(TaggedUrlLookupReader::class)->composer();
    $request = new TaggedUrlRequest('https://uams.edu/flu', steps: ['source' => 'anything']);

    expect($composer->channelOptions())->toBe([])
        ->and($composer->listFor('source', $request))->toBeNull()
        ->and($composer->compose($request))->toBe('https://uams.edu/flu');
});
