<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Support;

/**
 * The choices a tagged URL request carries (UAMS-Web/aether-redirects#26): its
 * destination, the channel and each cascade step's chosen option, choices from
 * other lookup lists, free-text custom values, request fields, and the suppress flag.
 *
 * A request made with a vanity URL also knows where it sits: the vanity domain and back-half, and
 * whether it is the `-QR` redirect (UAMS-Web/aether-redirects#125). A tagged-only request has neither.
 */
final readonly class TaggedUrlRequest
{
    /**
     * @param  string  $destination  The URL the tag is added to
     * @param  string|null  $channel  The chosen channel option's id
     * @param  array<array-key, string>  $steps  The chosen option id at each cascade step, by step
     * @param  array<array-key, string>  $lists  The chosen option id from other lookup lists, by list id
     * @param  array<array-key, string>  $custom  Free-text custom values, by step or list id
     * @param  array<array-key, string>  $fields  Request field values, by handle
     * @param  bool  $suppressUtm  Whether to leave every `utm_*` parameter out
     * @param  string|null  $vanity  The vanity domain and back-half, such as `uams.info/flu`, or null when there is none
     * @param  bool|null  $qr  Whether the redirect is the `-QR` one, or null for a request with no redirect
     */
    public function __construct(
        public string $destination,
        public ?string $channel = null,
        public array $steps = [],
        public array $lists = [],
        public array $custom = [],
        public array $fields = [],
        public bool $suppressUtm = false,
        public ?string $vanity = null,
        public ?bool $qr = null,
    ) {}

    /**
     * The same request, placed on a redirect.
     *
     * @param  string|null  $vanity  The vanity domain and back-half, or null when the redirect has no vanity domain
     * @param  bool  $qr  Whether the redirect is the `-QR` one
     */
    public function placedOn(?string $vanity, bool $qr): self
    {
        return new self($this->destination, $this->channel, $this->steps, $this->lists, $this->custom, $this->fields, $this->suppressUtm, $vanity, $qr);
    }

    /**
     * The choices as a vanity redirect keeps them until approval.
     *
     * @return array<string, mixed> The destination as typed, the choices, and the suppress flag
     */
    public function toStored(): array
    {
        return [
            'destination' => $this->destination,
            'channel' => $this->channel,
            'steps' => $this->steps,
            'lists' => $this->lists,
            'custom_values' => $this->custom,
            'request_fields' => $this->fields,
            'suppress_utm' => $this->suppressUtm,
        ];
    }

    /**
     * Read the choices a vanity redirect kept.
     *
     * Only text is read back: ids and typed values stay the strings they were saved as, and anything
     * else a hand-edited entry holds is ignored.
     *
     * @param  mixed  $stored  The stored value, as {@see toStored()} wrote it
     */
    public static function fromStored(mixed $stored): self
    {
        $stored = \is_array($stored) ? $stored : [];
        $destination = $stored['destination'] ?? null;
        $channel = $stored['channel'] ?? null;

        return new self(
            destination: \is_string($destination) ? $destination : '',
            channel: \is_string($channel) && $channel !== '' ? $channel : null,
            steps: self::texts($stored['steps'] ?? null),
            lists: self::texts($stored['lists'] ?? null),
            custom: self::texts($stored['custom_values'] ?? null),
            fields: self::texts($stored['request_fields'] ?? null),
            suppressUtm: ($stored['suppress_utm'] ?? false) === true,
        );
    }

    /**
     * The text entries of a stored map.
     *
     * @param  mixed  $value  The stored value
     * @return array<array-key, string> The entries that are text
     */
    private static function texts(mixed $value): array
    {
        return array_filter(\is_array($value) ? $value : [], \is_string(...));
    }
}
