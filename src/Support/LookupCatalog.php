<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Support;

/**
 * The tagged URL builder's lookup lists and options, and the cascade between them
 * (UAMS-Web/aether-redirects#26), with the rules decided in UAMS-Web/aether-redirects#45.
 *
 * - **A list's options** are its own active options in order, then the options of
 *   each member list in member order. An option whose value is already present is
 *   skipped, so a composed list shows no repeats. A member that leads back to a
 *   list already being read is not read again.
 * - **A cascade step's list** is named by the deepest choice that names one for
 *   that step; shallower choices are fallbacks, and a step no choice names uses its
 *   generic list.
 *
 * Pure: no facades and no I/O. The host's entries are read into it elsewhere.
 */
final readonly class LookupCatalog
{
    /**
     * The options of each list, by list id, in their stored order.
     *
     * @var array<string, list<LookupOption>>
     */
    private array $optionsByList;

    /**
     * @param  array<string, list<string>>  $members  The member list ids of each composed list, by list id
     * @param  iterable<LookupOption>  $options  Every option, of every list
     */
    public function __construct(
        private array $members,
        iterable $options,
    ) {
        // Group options by the list they belong to; an option in no list is unreachable.
        $grouped = [];

        foreach ($options as $option) {
            if ($option->list !== null) {
                $grouped[$option->list][] = $option;
            }
        }

        // Order each list's options by their order field, keeping stored order on ties.
        foreach ($grouped as $listId => $listOptions) {
            usort($listOptions, static fn (LookupOption $a, LookupOption $b): int => $a->order <=> $b->order);
            $grouped[$listId] = $listOptions;
        }

        $this->optionsByList = $grouped;
    }

    /**
     * The options a list offers: its own, then its members', with no value repeated.
     *
     * @param  string  $listId  The list entry's id
     * @return list<LookupOption> The active options, in the order a requester sees them
     */
    public function options(string $listId): array
    {
        $collected = [];
        $this->collect($listId, $collected, []);

        return array_values($collected);
    }

    /**
     * Find an option by id among every list, active or not.
     *
     * @param  string  $optionId  The option entry's id
     * @return LookupOption|null The option, or null when no list holds it
     */
    public function option(string $optionId): ?LookupOption
    {
        foreach ($this->optionsByList as $listOptions) {
            foreach ($listOptions as $option) {
                if ($option->id === $optionId) {
                    return $option;
                }
            }
        }

        return null;
    }

    /**
     * The list a cascade step offers, given the choices made so far.
     *
     * @param  string  $step  A cascade step, one of {@see TaggedUrlLookup::steps()}
     * @param  list<LookupOption>  $choices  The options chosen so far, shallowest first
     * @param  string|null  $genericList  The step's generic list id, or null when the site names none
     * @return string|null The list id, or null when neither a choice nor the generic list names one
     */
    public static function listForStep(string $step, array $choices, ?string $genericList): ?string
    {
        // The deepest choice that names a list for this step wins.
        foreach (array_reverse($choices) as $choice) {
            $pointer = $choice->pointerFor($step);

            if ($pointer !== null) {
                return $pointer;
            }
        }

        // No choice names one: the step's generic list.
        return $genericList;
    }

    /**
     * Add a list's options, then its members', to the collected set by value.
     *
     * @param  string  $listId  The list being read
     * @param  array<string, LookupOption>  $collected  The options gathered so far, by value
     * @param  array<string, string>  $reading  The lists already on the path to this one, by id
     */
    private function collect(string $listId, array &$collected, array $reading): void
    {
        // A member that leads back to a list on this path would never end.
        if (isset($reading[$listId])) {
            return;
        }

        $reading[$listId] = $listId;

        // The list's own options first, skipping retired ones and values already present.
        foreach ($this->optionsByList[$listId] ?? [] as $option) {
            if ($option->active && ! isset($collected[$option->value])) {
                $collected[$option->value] = $option;
            }
        }

        // Then each member list, in member order.
        foreach ($this->members[$listId] ?? [] as $memberId) {
            $this->collect($memberId, $collected, $reading);
        }
    }
}
