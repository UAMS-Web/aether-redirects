<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Support;

/**
 * Walks the tagged URL cascade and composes a request's tagged URL
 * (UAMS-Web/aether-redirects#26).
 *
 * The cascade starts at the channel list. Each later step offers the list named by
 * the deepest choice so far, the channel included, or the step's generic list when
 * no choice names one ({@see LookupCatalog::listForStep()}).
 *
 * Pure: the host's lists, generic lists and parameters are read into it elsewhere.
 */
final readonly class TaggedUrlComposer
{
    /**
     * @param  LookupCatalog  $catalog  The lookup lists and options
     * @param  string|null  $channelList  The channel list's id, or null when the site names none
     * @param  array<string, string>  $genericLists  Each step's generic list id, by step
     * @param  list<TaggedUrlParameter>  $parameters  The parameters a URL carries, in URL order
     */
    public function __construct(
        private LookupCatalog $catalog,
        private ?string $channelList,
        private array $genericLists,
        private array $parameters,
    ) {}

    /**
     * The options the channel step offers.
     *
     * @return list<LookupOption> The channel list's active options, or none when no list is named
     */
    public function channelOptions(): array
    {
        return $this->channelList === null ? [] : $this->catalog->options($this->channelList);
    }

    /**
     * The list a cascade step offers, given the request's earlier choices.
     *
     * @param  string  $step  A cascade step, one of {@see TaggedUrlLookup::steps()}
     * @param  TaggedUrlRequest  $request  The request so far; choices at this step and later are ignored
     * @return string|null The list id, or null when no choice and no generic list names one
     */
    public function listFor(string $step, TaggedUrlRequest $request): ?string
    {
        return LookupCatalog::listForStep($step, $this->choicesBefore($step, $request), $this->genericLists[$step] ?? null);
    }

    /**
     * The options a cascade step offers, given the request's earlier choices.
     *
     * @param  string  $step  A cascade step, one of {@see TaggedUrlLookup::steps()}
     * @param  TaggedUrlRequest  $request  The request so far
     * @return list<LookupOption> The step's active options, or none when it has no list
     */
    public function optionsFor(string $step, TaggedUrlRequest $request): array
    {
        $list = $this->listFor($step, $request);

        return $list === null ? [] : $this->catalog->options($list);
    }

    /**
     * The tagged URL for a request.
     *
     * @param  TaggedUrlRequest  $request  The request's choices
     * @return string The destination with every defined parameter that has a value
     */
    public function compose(TaggedUrlRequest $request): string
    {
        $pairs = [];

        foreach ($this->parameters as $parameter) {
            $pairs[] = [$parameter->name, $this->value($parameter, $request)];
        }

        return TaggedUrl::build($request->destination, $pairs, $request->suppressUtm);
    }

    /**
     * A parameter's value for a request.
     *
     * @param  TaggedUrlParameter  $parameter  The parameter
     * @param  TaggedUrlRequest  $request  The request's choices
     * @return string The value, or an empty string when the request gives none
     */
    private function value(TaggedUrlParameter $parameter, TaggedUrlRequest $request): string
    {
        $source = $parameter->source;

        return match ($parameter->from) {
            TaggedUrlParameter::FROM_STEP => $this->optionValue($request->steps[$source] ?? null, $request->custom[$source] ?? null),
            TaggedUrlParameter::FROM_LIST => $this->optionValue($request->lists[$source] ?? null, $request->custom[$source] ?? null),
            TaggedUrlParameter::FROM_REQUEST_FIELD => trim($request->fields[$source] ?? ''),
            default => $source,
        };
    }

    /**
     * The value of a chosen option, with any custom value appended.
     *
     * @param  string|null  $optionId  The chosen option's id
     * @param  string|null  $custom  The free-text custom value beside it
     * @return string The value, or an empty string when nothing is chosen or the option is gone
     */
    private function optionValue(?string $optionId, ?string $custom): string
    {
        $option = $optionId === null ? null : $this->catalog->option($optionId);

        return $option instanceof LookupOption ? TaggedUrl::value($option->value, $custom) : '';
    }

    /**
     * The options chosen before a step, the channel first, in cascade order.
     *
     * @param  string  $step  The step being offered
     * @param  TaggedUrlRequest  $request  The request so far
     * @return list<LookupOption> The earlier choices that still exist
     */
    private function choicesBefore(string $step, TaggedUrlRequest $request): array
    {
        $ids = $request->channel === null ? [] : [$request->channel];

        foreach (TaggedUrlLookup::steps() as $earlier) {
            if ($earlier === $step) {
                break;
            }

            if (isset($request->steps[$earlier])) {
                $ids[] = $request->steps[$earlier];
            }
        }

        $choices = [];

        foreach ($ids as $id) {
            $option = $this->catalog->option($id);

            if ($option instanceof LookupOption) {
                $choices[] = $option;
            }
        }

        return $choices;
    }
}
