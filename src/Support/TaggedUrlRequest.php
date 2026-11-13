<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Support;

/**
 * The choices a tagged URL request carries (UAMS-Web/aether-redirects#26): its
 * destination, the channel and each cascade step's chosen option, choices from
 * other lookup lists, free-text custom values, request fields, and the suppress flag.
 */
final readonly class TaggedUrlRequest
{
    /**
     * @param  string  $destination  The URL the tag is added to
     * @param  string|null  $channel  The chosen channel option's id
     * @param  array<string, string>  $steps  The chosen option id at each cascade step, by step
     * @param  array<string, string>  $lists  The chosen option id from other lookup lists, by list id
     * @param  array<string, string>  $custom  Free-text custom values, by step or list id
     * @param  array<string, string>  $fields  Request field values, by handle
     * @param  bool  $suppressUtm  Whether to leave every `utm_*` parameter out
     */
    public function __construct(
        public string $destination,
        public ?string $channel = null,
        public array $steps = [],
        public array $lists = [],
        public array $custom = [],
        public array $fields = [],
        public bool $suppressUtm = false,
    ) {}
}
