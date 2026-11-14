<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Services;

use Illuminate\Support\Collection;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Globals\GlobalSet as GlobalSetItem;
use Statamic\Globals\Variables;
use Statamic\Stache\Query\EntryQueryBuilder;
use UAMSWeb\Aether\Redirects\Support\LookupCatalog;
use UAMSWeb\Aether\Redirects\Support\LookupOption;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlComposer;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlLookup;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlParameter;

/**
 * Reads the host's tagged URL lookup data into a {@see TaggedUrlComposer}
 * (UAMS-Web/aether-redirects#26).
 *
 * The add-on ships no lookup data: the lists and options are the published entries
 * of the host's `lookup_lists` and `lookup_options` collections, and the channel
 * list, each step's generic list and the parameters are the default site's values
 * of the host's `tagged_url_parameters` global. Anything the host has not defined
 * reads as empty.
 */
class TaggedUrlLookupReader
{
    /**
     * Read the host's lookup data.
     *
     * @return TaggedUrlComposer The cascade and URL composer over that data
     */
    public function composer(): TaggedUrlComposer
    {
        $variables = $this->parameters();

        // Each step's generic list, where the site names one.
        $generic = [];

        foreach (TaggedUrlLookup::steps() as $step) {
            $list = TaggedUrlParameter::firstId($variables?->get('fallback_'.$step));

            if ($list !== null) {
                $generic[$step] = $list;
            }
        }

        return new TaggedUrlComposer(
            $this->catalog(),
            TaggedUrlParameter::firstId($variables?->get(TaggedUrlLookup::CHANNEL_LIST_FIELD)),
            $generic,
            $this->parameterList(),
        );
    }

    /**
     * The parameters a tagged URL carries, from the host's parameters global.
     *
     * @return list<TaggedUrlParameter> The parameters in URL order, or none when the host defines none
     */
    public function parameterList(): array
    {
        $variables = $this->parameters();

        return TaggedUrlParameter::fromGlobal(
            $variables?->get('standard_parameters'),
            $variables?->get('custom_parameters'),
        );
    }

    /**
     * Read the lists and options collections.
     *
     * @return LookupCatalog The lists' members and every option
     */
    public function catalog(): LookupCatalog
    {
        // Each list's member lists, in member order.
        $members = [];

        foreach ($this->entries(TaggedUrlLookup::LISTS_COLLECTION) as $list) {
            $members[self::text($list->id())] = self::ids($list->get('members'));
        }

        // Every option, with its pointer for each step it names.
        $options = [];

        foreach ($this->entries(TaggedUrlLookup::OPTIONS_COLLECTION) as $option) {
            $pointers = [];

            foreach (TaggedUrlLookup::steps() as $step) {
                $pointer = TaggedUrlParameter::firstId($option->get($step));

                if ($pointer !== null) {
                    $pointers[$step] = $pointer;
                }
            }

            $options[] = new LookupOption(
                self::text($option->id()),
                TaggedUrlParameter::firstId($option->get('list')),
                self::text($option->get('title')),
                self::text($option->get('value')),
                $option->get('active') !== false,
                self::integer($option->get('order')),
                $pointers,
            );
        }

        return new LookupCatalog($members, $options);
    }

    /**
     * The published entries of a collection.
     *
     * @param  string  $collection  The collection handle
     * @return list<StatamicEntry> The entries, an empty list when the collection is absent
     */
    private function entries(string $collection): array
    {
        // A status query needs the collection, so a host without it has no entries.
        if (! CollectionFacade::findByHandle($collection) instanceof StatamicCollection) {
            return [];
        }

        /** @var EntryQueryBuilder $query */
        $query = Entry::query();
        /** @var EntryQueryBuilder $query */
        $query = $query->where('collection', $collection);
        /** @var EntryQueryBuilder $query */
        $query = $query->whereStatus('published');

        /** @var Collection<int, mixed> $found */
        $found = $query->get();

        // An entry query yields entries only, so the filter narrows the type for static
        // analysis and never drops anything, and the query's list keeps its keys in order
        // @pest-mutate-ignore:UnwrapArrayFilter,UnwrapArrayValues,InstanceOfToTrue
        return array_values(array_filter($found->all(), static fn (mixed $entry): bool => $entry instanceof StatamicEntry));
    }

    /**
     * The default site's values of the parameters global, or null when the host has none.
     *
     * @return Variables|null The variables
     */
    private function parameters(): ?Variables
    {
        /** @var GlobalSetItem|null $global */
        $global = GlobalSet::findByHandle(TaggedUrlLookup::PARAMETERS_GLOBAL);

        if ($global === null) {
            return null;
        }

        /** @var \Statamic\Sites\Site $site */
        $site = Site::default();
        $variables = $global->in($site->handle());

        // in() returns variables or null, so admitting anything returns the same value
        // @pest-mutate-ignore:InstanceOfToTrue
        return $variables instanceof Variables ? $variables : null;
    }

    /**
     * The entry ids of an entries field value.
     *
     * @param  mixed  $value  The stored value: an id, a list of ids, or nothing
     * @return list<string> The ids, in stored order
     */
    private static function ids(mixed $value): array
    {
        $ids = [];

        foreach (\is_array($value) ? $value : [$value] as $id) {
            if (\is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * A stored whole number, or zero when none is stored.
     *
     * @param  mixed  $value  The stored value
     * @return int The number
     */
    private static function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * A stored text value, or an empty string when none is stored.
     *
     * @param  mixed  $value  The stored value
     * @return string The text
     */
    private static function text(mixed $value): string
    {
        return \is_string($value) || is_numeric($value) ? (string) $value : '';
    }
}
