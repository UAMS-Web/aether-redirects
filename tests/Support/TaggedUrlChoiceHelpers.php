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
use UAMSWeb\Aether\Redirects\Services\TaggedUrlRequestStore;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlLookup;

/**
 * The site the tagged URL choice tests run against (UAMS-Web/aether-redirects#119): the request
 * collection, a small cascade of lists and options, and the parameters global.
 */

/**
 * Save the collections, the lists and the parameters.
 */
function tcSetUp(): void
{
    foreach ([TaggedUrlRequestStore::COLLECTION, TaggedUrlLookup::LISTS_COLLECTION, TaggedUrlLookup::OPTIONS_COLLECTION] as $handle) {
        $collection = Collection::make($handle);
        Assert::assertInstanceOf(StatamicCollection::class, $collection);
        $collection->save();
    }

    tcLookups();
    tcParameters(tcDefaultParameters());
}

/**
 * Save a lookup entry.
 *
 * @param  array<string, mixed>  $data  The entry's fields.
 */
function tcEntry(string $collection, string $id, array $data, bool $published = true): void
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
 * The site's lists: channels leading to sources, and a source leading to its own media.
 */
function tcLookups(): void
{
    $lists = TaggedUrlLookup::LISTS_COLLECTION;
    $options = TaggedUrlLookup::OPTIONS_COLLECTION;

    foreach (['channels', 'social-sources', 'generic-sources', 'facebook-media', 'generic-media', 'audiences', 'regions'] as $list) {
        tcEntry($lists, $list, ['title' => $list]);
    }

    tcEntry($options, 'ch-social', ['title' => 'Social', 'value' => 'social', 'list' => ['channels'], 'source' => ['social-sources']]);
    tcEntry($options, 'ch-print', ['title' => 'Print', 'value' => 'print', 'list' => ['channels']]);
    tcEntry($options, 'src-facebook', ['title' => 'Facebook', 'value' => 'facebook', 'list' => ['social-sources'], 'medium' => ['facebook-media']]);
    tcEntry($options, 'src-linkedin', ['title' => 'LinkedIn', 'value' => 'linkedin', 'list' => ['social-sources']]);
    tcEntry($options, 'src-flyer', ['title' => 'Flyer', 'value' => 'flyer', 'list' => ['generic-sources']]);
    tcEntry($options, 'med-feed', ['title' => 'Feed', 'value' => 'feed', 'list' => ['facebook-media']]);
    tcEntry($options, 'med-other', ['title' => 'Other', 'value' => 'other', 'list' => ['generic-media']]);
    tcEntry($options, 'aud-staff', ['title' => 'Staff', 'value' => 'Staff', 'list' => ['audiences']]);
    tcEntry($options, 'aud-public', ['title' => 'Public', 'value' => 'Public', 'list' => ['audiences']]);
    tcEntry($options, 'reg-north', ['title' => 'North', 'value' => 'north', 'list' => ['regions']]);
}

/**
 * The parameters the tests use unless one overrides them: the standard ones, an audience list and a request field.
 *
 * @return array<string, mixed>
 */
function tcDefaultParameters(): array
{
    return [
        TaggedUrlLookup::CHANNEL_LIST_FIELD => ['channels'],
        'fallback_source' => ['generic-sources'],
        'fallback_medium' => ['generic-media'],
        'standard_parameters' => ['utm_source', 'utm_medium', 'utm_campaign'],
        'custom_parameters' => [
            ['name' => 'audience', 'enabled' => true, 'order' => 1, 'source_type' => 'list', 'list' => ['audiences']],
            ['name' => 'vanity', 'enabled' => true, 'order' => 2, 'source_type' => 'request_field', 'request_field' => 'vanity_path'],
            ['name' => 'region', 'enabled' => true, 'order' => 3, 'source_type' => 'list', 'list' => ['regions']],
        ],
    ];
}

/**
 * Save the parameters global with the default site's values.
 *
 * @param  array<string, mixed>  $data  The global's values.
 */
function tcParameters(array $data): void
{
    $set = GlobalSet::find(TaggedUrlLookup::PARAMETERS_GLOBAL) ?? GlobalSet::make(TaggedUrlLookup::PARAMETERS_GLOBAL);
    Assert::assertInstanceOf(StatamicGlobalSet::class, $set);
    $set->title('Tagged URL parameters');
    $set->save();

    $site = Site::default();
    Assert::assertInstanceOf(StatamicSite::class, $site);
    $variables = $set->in($site->handle()) ?? $set->makeLocalization($site->handle());
    Assert::assertInstanceOf(Variables::class, $variables);
    $variables->data($data);
    $variables->save();
}

/**
 * A request made with a full set of choices.
 *
 * @return array<string, mixed>
 */
function tcChoices(): array
{
    return [
        'channel' => 'ch-social',
        'steps' => ['source' => 'src-facebook', 'medium' => 'med-feed'],
        'lists' => ['audiences' => 'aud-staff'],
        'custom_values' => ['audiences' => 'Extra'],
        'vanity_path' => '/flu',
    ];
}
