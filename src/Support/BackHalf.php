<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Support;

use UAMSWeb\Aether\Redirects\Exceptions\CodeGenerationException;

/**
 * The generated back-half of a requested vanity URL (UAMS-Web/aether-redirects#15):
 * 7 characters drawn from a base-62 alphabet, unique without regard to case.
 *
 * The back-half keeps its mixed case for display, but two back-halves that
 * differ only in case are the same back-half, so case-blind uniqueness leaves
 * 36^7, about 78 billion, distinct values.
 *
 * A back-half, once issued, is never issued again: links to it stay in
 * circulation after its redirect's validity window ends, so a reused back-half
 * would send an old link to a new destination. {@see isIssued()} therefore
 * ignores validity windows. It skips rejected rows, because a rejected request
 * never served and so left no link in circulation.
 */
final class BackHalf
{
    /**
     * The characters a back-half may contain: `A`-`Z`, `a`-`z`, `0`-`9`.
     */
    public const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /**
     * How many characters a back-half has.
     */
    // PCOV attributes no coverage to a constant's initializer, so Pest selects no test for its mutants and
    // reports them uncovered. A changed value is observable, and the generator assertions in `BackHalfTest` pin it.
    // @pest-mutate-ignore:IncrementInteger,DecrementInteger
    public const int LENGTH = 7;

    /**
     * How many draws to try before reporting failure to the requester.
     */
    // PCOV attributes no coverage to a constant's initializer, so Pest selects no test for its mutants and
    // reports them uncovered. A changed value is observable, and the generator assertions in `BackHalfTest` pin it.
    // @pest-mutate-ignore:IncrementInteger,DecrementInteger
    public const int MAX_ATTEMPTS = 10;

    /**
     * The entry field that marks a request's source as a generated back-half.
     */
    public const string FIELD = 'generated_back_half';

    /**
     * The container binding that resolves the generator a request draws with,
     * so a host application or a test can replace it.
     */
    public const string GENERATOR = 'aether-redirects.back-half-generator';

    /**
     * Return the generator configured for back-halves.
     *
     * @return RandomCode The base-62, 7-character generator
     */
    public static function generator(): RandomCode
    {
        return new RandomCode(self::ALPHABET, self::LENGTH, self::MAX_ATTEMPTS);
    }

    /**
     * Generate a back-half that no existing row has issued.
     *
     * @param  iterable<int, mixed>  $existingRows  The stored redirect rows and requests on the same short domain
     * @param  RandomCode|null  $generator  The generator to draw with; null for {@see generator()}
     * @return string A back-half free on that domain, in its drawn case
     *
     * @throws CodeGenerationException When every allowed draw was already issued
     */
    public static function generate(iterable $existingRows, ?RandomCode $generator = null): string
    {
        // Materialize the rows once, since the check runs once per draw.
        $rows = [...$existingRows];

        return ($generator ?? self::generator())->generate(
            static fn (string $candidate): bool => self::isIssued($candidate, $rows),
        );
    }

    /**
     * Whether a stored row's source is a generated back-half.
     *
     * Only a row the request utility marked counts; a typed path, a legacy row or
     * a hand-edited entry carries no flag and is not one.
     *
     * @param  array<string, mixed>  $row  The stored row
     * @return bool True when the row's source was generated
     */
    public static function wasGenerated(array $row): bool
    {
        return ($row[self::FIELD] ?? false) === true;
    }

    /**
     * Whether a back-half has already been issued by any of the given rows.
     *
     * A row issues its source when it is approved (or a legacy row with no
     * status) or pending, whatever its validity window, including one that has
     * ended. The comparison ignores case and the leading slash a stored source
     * carries.
     *
     * @param  string  $candidate  The back-half to test, with or without a leading slash
     * @param  iterable<int, mixed>  $existingRows  The stored redirect rows (raw data, defensively untyped)
     * @return bool True when some row has already issued that back-half
     */
    public static function isIssued(string $candidate, iterable $existingRows): bool
    {
        // Compare on the lowercased canonical form, as the collision check does.
        $candidateKey = RedirectSource::collisionKey($candidate);

        foreach ($existingRows as $row) {
            // Skip anything that is not a stored row with a string source.
            if (! \is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $source = $row['source'] ?? null;

            if (! \is_string($source)) {
                continue;
            }

            // A rejected request never served, so it issued nothing.
            if (RedirectStatus::fromRow($row) === RedirectStatus::Rejected) {
                continue;
            }

            // Any other row with the same source has issued it, whatever its window.
            if (RedirectSource::collisionKey($source) === $candidateKey) {
                return true;
            }
        }

        return false;
    }
}
