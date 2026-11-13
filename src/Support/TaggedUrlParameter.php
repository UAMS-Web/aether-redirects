<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Support;

/**
 * One parameter a tagged URL carries, and where its value comes from
 * (UAMS-Web/aether-redirects#26).
 *
 * The Google Analytics standard parameters have fixed sources, decided on #26:
 * `utm_source` and the parameters named after a cascade step take that step's
 * choice, and `utm_id`, `utm_campaign` and `utm_marketing_tactic` take the request
 * field of the same name. The `tagged_url_parameters` global only chooses which of
 * them a URL carries. Custom parameters name their own source in that global's grid.
 */
final readonly class TaggedUrlParameter
{
    /**
     * The value is the choice made at a cascade step.
     */
    public const string FROM_STEP = 'step';

    /**
     * The value is a request field.
     */
    public const string FROM_REQUEST_FIELD = 'request_field';

    /**
     * The value is the choice made from a lookup list.
     */
    public const string FROM_LIST = 'list';

    /**
     * The value is fixed text.
     */
    public const string FROM_FIXED_VALUE = 'fixed_value';

    /**
     * The standard parameters, in the order a URL carries them, each with its source.
     *
     * A method rather than a constant, so a mutation run sees each entry covered by
     * the tests that read it.
     *
     * @return array<string, array{0: string, 1: string}> The source type and source, by name
     */
    public static function standard(): array
    {
        return [
            'utm_id' => [self::FROM_REQUEST_FIELD, 'utm_id'],
            'utm_source' => [self::FROM_STEP, 'source'],
            'utm_medium' => [self::FROM_STEP, 'medium'],
            'utm_campaign' => [self::FROM_REQUEST_FIELD, 'utm_campaign'],
            'utm_source_platform' => [self::FROM_STEP, 'source_platform'],
            'utm_term' => [self::FROM_STEP, 'term'],
            'utm_content' => [self::FROM_STEP, 'content'],
            'utm_creative_format' => [self::FROM_STEP, 'creative_format'],
            'utm_marketing_tactic' => [self::FROM_REQUEST_FIELD, 'utm_marketing_tactic'],
        ];
    }

    /**
     * @param  string  $name  The parameter's name in the URL
     * @param  string  $from  Where its value comes from, one of the `FROM_*` constants
     * @param  string  $source  The step, request field handle, list id or fixed text it reads
     */
    public function __construct(
        public string $name,
        public string $from,
        public string $source,
    ) {}

    /**
     * The parameters a tagged URL carries, from the `tagged_url_parameters` global's values.
     *
     * The checked standard parameters come first, in {@see standard()} order, then the
     * enabled custom parameters by their `order`, ties kept in grid order. A custom row
     * with no usable name, or with no source for its source type, is left out.
     *
     * @param  mixed  $standard  The `standard_parameters` value: the checked names
     * @param  mixed  $custom  The `custom_parameters` value: the grid rows
     * @return list<self> The parameters, in URL order
     */
    public static function fromGlobal(mixed $standard, mixed $custom): array
    {
        // The checked standard parameters, in the standard order.
        $checked = \is_array($standard) ? $standard : [];
        $parameters = [];

        foreach (self::standard() as $name => [$from, $source]) {
            if (\in_array($name, $checked, true)) {
                $parameters[] = new self($name, $from, $source);
            }
        }

        // The enabled, usable custom rows, by order.
        $rows = [];

        foreach (\is_array($custom) ? $custom : [] as $row) {
            $parameter = \is_array($row) ? self::fromCustomRow($row) : null;

            if ($parameter instanceof self) {
                $rows[] = [$parameter, is_numeric($row['order'] ?? null) ? (int) $row['order'] : 0];
            }
        }

        usort($rows, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

        return [...$parameters, ...array_column($rows, 0)];
    }

    /**
     * A custom parameter from one grid row, or null when the row is off or unusable.
     *
     * @param  array<mixed>  $row  The grid row
     * @return self|null The parameter
     */
    private static function fromCustomRow(array $row): ?self
    {
        // A row switched off is left out of every new URL.
        if (($row['enabled'] ?? true) === false) {
            return null;
        }

        $name = $row['name'] ?? null;

        if (! \is_string($name) || preg_match('/\A[A-Za-z0-9_]+\z/', $name) !== 1) {
            return null;
        }

        // The source field that matches the row's source type.
        $from = $row['source_type'] ?? self::FROM_LIST;
        $source = match ($from) {
            self::FROM_REQUEST_FIELD => $row['request_field'] ?? null,
            self::FROM_LIST => self::firstId($row['list'] ?? null),
            self::FROM_FIXED_VALUE => $row['fixed_value'] ?? null,
            default => null,
        };

        if (! \is_string($source) || $source === '') {
            return null;
        }

        return new self($name, $from, $source);
    }

    /**
     * The first entry id of an entries field value, stored as an id or a list of ids.
     *
     * @param  mixed  $value  The stored value
     * @return string|null The id, or null when none is stored
     */
    public static function firstId(mixed $value): ?string
    {
        if (\is_array($value)) {
            $value = reset($value);
        }

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
