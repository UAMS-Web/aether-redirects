<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Support;

use UAMSWeb\Aether\Redirects\Exceptions\CodeGenerationException;

/**
 * The generated code of a short link on `go.uams.edu` (UAMS-Web/aether-redirects#84):
 * 6 characters drawn from lowercase letters and digits with the look-alikes `0`,
 * `o`, `1`, `l` and `i` removed.
 *
 * Drawn by the same {@see RandomCode} helper as {@see BackHalf}, as the decision on
 * #15 and #84 says: the two formats differ only in configuration. A code, once
 * issued, is never issued again, so the issued check is {@see BackHalf::isIssued()}:
 * case-blind, blind to validity windows, and skipping rejected rows.
 */
final class ShortCode
{
    /**
     * The redirect domain short links are made on.
     */
    public const string HOST = 'go.uams.edu';

    /**
     * The container binding that resolves the generator a short link draws with,
     * so a host application or a test can replace it.
     */
    public const string GENERATOR = 'aether-redirects.short-code-generator';

    /**
     * The characters a code may contain: `a`-`z` without `i`, `l` and `o`, and `2`-`9`.
     */
    public const string ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /**
     * How many characters a code has.
     */
    // PCOV attributes no coverage to a constant's initializer, so Pest selects no test for its mutants and
    // reports them uncovered. A changed value is observable, and the generator assertions in `ShortCodeTest` pin it.
    // @pest-mutate-ignore:IncrementInteger,DecrementInteger
    public const int LENGTH = 6;

    /**
     * How many draws to try before reporting failure to the requester.
     */
    // PCOV attributes no coverage to a constant's initializer, so Pest selects no test for its mutants and
    // reports them uncovered. A changed value is observable, and the generator assertions in `ShortCodeTest` pin it.
    // @pest-mutate-ignore:IncrementInteger,DecrementInteger
    public const int MAX_ATTEMPTS = 10;

    /**
     * Return the generator configured for short-link codes.
     *
     * @return RandomCode The 6-character generator over the restricted alphabet
     */
    public static function generator(): RandomCode
    {
        return new RandomCode(self::ALPHABET, self::LENGTH, self::MAX_ATTEMPTS);
    }

    /**
     * Whether a value is in the short-code format: exactly the configured length, from the alphabet.
     *
     * @param  string  $value  The value, without a leading slash
     * @return bool True for a well-formed code
     */
    public static function isCode(string $value): bool
    {
        return \strlen($value) === self::LENGTH && strspn($value, self::ALPHABET) === self::LENGTH;
    }

    /**
     * Generate a code that no existing rule or request on the short-link domain has issued.
     *
     * @param  iterable<int, mixed>  $hostRows  The rules and requests on {@see HOST}: imported rules, vanity paths and codes
     * @param  RandomCode|null  $generator  The generator to draw with; null for {@see generator()}
     * @return string A free code
     *
     * @throws CodeGenerationException When every allowed draw was already issued
     */
    public static function generate(iterable $hostRows, ?RandomCode $generator = null): string
    {
        // Materialize the rows once, since the check runs once per draw.
        $rows = [...$hostRows];

        return ($generator ?? self::generator())->generate(
            static fn (string $candidate): bool => BackHalf::isIssued($candidate, $rows),
        );
    }
}
