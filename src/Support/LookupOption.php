<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Support;

/**
 * One option of a tagged URL lookup list (UAMS-Web/aether-redirects#26), as the
 * `lookup_options` collection stores it (UAMS-Web/aether-redirects#45).
 *
 * An option belongs to one list, carries the label a requester sees and the value
 * put in the tagged URL, and may name, for each later cascade step, the list that
 * step offers once this option is chosen.
 */
final readonly class LookupOption
{
    /**
     * @param  string  $id  The option entry's id
     * @param  string|null  $list  The id of the list the option belongs to, or null when it names none
     * @param  string  $label  The text a requester sees
     * @param  string  $value  The text put in the tagged URL
     * @param  bool  $active  Whether the option is still offered
     * @param  int  $order  Its position within its list, lowest first
     * @param  array<string, string>  $pointers  The downstream list id for each cascade step it names, by step
     */
    public function __construct(
        public string $id,
        public ?string $list,
        public string $label,
        public string $value,
        public bool $active = true,
        public int $order = 0,
        public array $pointers = [],
    ) {}

    /**
     * The list this option names for a cascade step, or null when it names none.
     *
     * @param  string  $step  A cascade step, one of {@see TaggedUrlLookup::steps()}
     * @return string|null The downstream list id
     */
    public function pointerFor(string $step): ?string
    {
        return $this->pointers[$step] ?? null;
    }
}
