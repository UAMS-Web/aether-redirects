<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Support;

/**
 * Assembles a tagged URL from a destination and its parameter values
 * (UAMS-Web/aether-redirects#26), with the rules of the spreadsheet it retires
 * (UAMS-Web/aether-redirects#45).
 *
 * - Parameters are added in the order given, each name and value percent-encoded
 *   (RFC 3986), so a value with a space or an `&` still yields a valid URL.
 * - A parameter with an empty value is left out.
 * - A parameter the destination's query already carries is replaced, not repeated.
 * - The destination's fragment stays at the end.
 * - With the suppress flag, every `utm_*` parameter is left out.
 *
 * Pure: no facades and no I/O.
 */
final class TaggedUrl
{
    /**
     * The prefix of the Google Analytics campaign parameters the suppress flag leaves out.
     */
    public const string UTM_PREFIX = 'utm_';

    /**
     * Build the tagged URL.
     *
     * @param  string  $destination  The URL the tag is added to
     * @param  list<array{0: string, 1: string}>  $parameters  Each parameter's name and value, in URL order
     * @param  bool  $suppressUtm  Whether to leave every `utm_*` parameter out
     * @return string The destination with its parameters added
     */
    public static function build(string $destination, array $parameters, bool $suppressUtm = false): string
    {
        // Keep the parameters that will be written: a value, and not suppressed.
        $written = [];

        foreach ($parameters as [$name, $value]) {
            if ($value === '' || ($suppressUtm && str_starts_with($name, self::UTM_PREFIX))) {
                continue;
            }

            $written[$name] = $value;
        }

        // Split off the fragment, then the existing query.
        [$beforeFragment, $fragment] = self::splitOnce($destination, '#');
        [$base, $query] = self::splitOnce($beforeFragment, '?');

        // Keep the existing pairs the new parameters do not replace.
        $pairs = [];

        foreach ($query === null || $query === '' ? [] : explode('&', $query) as $pair) {
            $existingName = rawurldecode(explode('=', $pair)[0]);

            if (! \array_key_exists($existingName, $written)) {
                $pairs[] = $pair;
            }
        }

        // Then the new parameters, encoded, in their order.
        foreach ($written as $name => $value) {
            $pairs[] = rawurlencode((string) $name).'='.rawurlencode($value);
        }

        $url = $pairs === [] ? $base : $base.'?'.implode('&', $pairs);

        return $fragment === null ? $url : $url.'#'.$fragment;
    }

    /**
     * The value of a parameter taken from a list choice and an optional free-text custom value.
     *
     * A custom value is appended to the list value with `_`, and the joined result is
     * lowercased. With no custom value the list value is used as it stands.
     *
     * @param  string  $listValue  The chosen option's value
     * @param  string|null  $custom  The requester's free-text custom value, if any
     * @return string The parameter's value
     */
    public static function value(string $listValue, ?string $custom): string
    {
        // Surrounding whitespace in a typed value is never meant.
        $custom = trim($custom ?? '');

        if ($custom === '') {
            return $listValue;
        }

        return mb_strtolower($listValue.'_'.$custom);
    }

    /**
     * Split a string at the first occurrence of a separator.
     *
     * @param  string  $subject  The string to split
     * @param  non-empty-string  $separator  The one-character separator
     * @return array{0: string, 1: string|null} The part before, and the part after or null when absent
     */
    private static function splitOnce(string $subject, string $separator): array
    {
        $parts = explode($separator, $subject, 2);

        return [$parts[0], $parts[1] ?? null];
    }
}
