<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Support;

/**
 * The names the tagged URL builder's lookup data is stored under
 * (UAMS-Web/aether-redirects#45).
 *
 * The add-on ships the blueprints for these and no data: the host defines the two
 * collections and the global set with these handles, and the builder reads them by
 * the same handles.
 */
final readonly class TaggedUrlLookup
{
    /**
     * The handle of the collection of lookup lists.
     */
    public const string LISTS_COLLECTION = 'lookup_lists';

    /**
     * The handle of the collection of lookup options, each belonging to a list.
     */
    public const string OPTIONS_COLLECTION = 'lookup_options';

    /**
     * The handle of the global set that defines the parameters a tagged URL carries
     * and the generic fallback list of each cascade step.
     */
    public const string PARAMETERS_GLOBAL = 'tagged_url_parameters';

    /**
     * The field on the parameters global that names the list of marketing channels,
     * the first choice of the cascade (UAMS-Web/aether-redirects#26). A channel option
     * names the source list through its `source` pointer.
     */
    public const string CHANNEL_LIST_FIELD = 'channel_list';

    /**
     * The cascade steps an option can point downstream at, in cascade order. Each is
     * a field handle on an option, and `fallback_<step>` on the parameters global
     * names the step's generic list. `source` follows the channel choice.
     *
     * A method rather than a constant (UAMS-Web/aether-redirects#77): PCOV gives a
     * constant's initializer no coverage, so a mutation run could select no test for
     * a removed step. Here each removed step is covered, and `LookupBlueprintsTest`
     * fails on it by comparing the steps with the blueprints' fields.
     *
     * @return list<string> The step handles
     */
    public static function steps(): array
    {
        return [
            'source',
            'source_platform',
            'medium',
            'creative_format',
            'specifications',
            'placement',
            'term',
            'content',
        ];
    }
}
