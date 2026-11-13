<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Support;

use Carbon\CarbonImmutable;

/**
 * The rules a back-half the requester types must pass (UAMS-Web/aether-redirects#16).
 *
 * A typed back-half, such as `hotdog` in `uams.health/hotdog`, shares one
 * limited namespace on its short domain with the back-halves the add-on
 * generates. Four rules protect it, checked in this order so the requester sees
 * the first that fails:
 *
 * - Form: letters, numbers and hyphens, starting and ending with a letter or
 *   number, at most {@see MAX_LENGTH} characters.
 * - Suffix: never `-QR` in any case, because that suffix names the QR redirect
 *   of a vanity URL (UAMS-Web/aether-redirects#14).
 * - Reserved: not a word on the `back_half.reserved` setting, which ships empty.
 * - Existing: not already held on the domain, in any case, by a pending request
 *   or by an approved redirect that is live, not yet started, or still inside
 *   the reuse buffer after its last day ({@see RedirectHosts::REUSE_BUFFER_WEEKS}).
 *   A rejected request holds nothing.
 *
 * The same rules run again at approval, against the approved rows alone, so two
 * pending requests for one back-half cannot both go live.
 */
final class TypedBackHalf
{
    /**
     * The most characters a typed back-half may have.
     */
    // PCOV attributes no coverage to a constant's initializer, so Pest selects no test for its mutants and
    // reports them uncovered. A changed value is observable, and the length tests in `TypedBackHalfTest` pin it.
    // @pest-mutate-ignore:IncrementInteger,DecrementInteger
    public const int MAX_LENGTH = 50;

    /**
     * The suffix that names the QR redirect of a vanity URL, matched in any case.
     */
    public const string QR_SUFFIX = '-QR';

    /**
     * The setting that holds the reserved words.
     */
    private const string RESERVED_KEY = 'aether-redirects.back_half.reserved';

    /**
     * Return why a typed back-half is refused, or null when it passes.
     *
     * @param  string  $source  The typed source, with or without its leading slash
     * @param  iterable<int, mixed>  $rows  The stored rows on the same short domain that count against it
     * @return string|null The message naming the rule that failed, or null when every rule passes
     */
    public static function error(string $source, iterable $rows): ?string
    {
        $backHalf = trim(trim($source), '/');

        if (preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\z/', $backHalf) !== 1) {
            return __('A back-half uses only letters, numbers and hyphens, and starts and ends with a letter or number.');
        }

        if (\strlen($backHalf) > self::MAX_LENGTH) {
            return __('A back-half is :max characters or fewer.', ['max' => self::MAX_LENGTH]);
        }

        if (str_ends_with(strtolower($backHalf), strtolower(self::QR_SUFFIX))) {
            return __('A back-half cannot end in :suffix, which names the QR redirect of a vanity URL.', ['suffix' => self::QR_SUFFIX]);
        }

        if (\in_array(strtolower($backHalf), self::reserved(), true)) {
            return __('":backHalf" is a reserved word and cannot be used as a back-half.', ['backHalf' => $backHalf]);
        }

        if (self::isTaken($backHalf, $rows)) {
            return __('The back-half ":backHalf" is already in use on this domain, in any case.', ['backHalf' => $backHalf]);
        }

        return null;
    }

    /**
     * Whether a pending request or an approved redirect on the domain holds the back-half.
     *
     * @param  string  $backHalf  The typed back-half, without its leading slash
     * @param  iterable<int, mixed>  $rows  The stored rows on the same short domain
     */
    private static function isTaken(string $backHalf, iterable $rows): bool
    {
        $key = RedirectSource::collisionKey($backHalf);
        $now = CarbonImmutable::now();

        foreach ($rows as $row) {
            if (! \is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $source = $row['source'] ?? null;

            if (! \is_string($source) || RedirectSource::collisionKey($source) !== $key) {
                continue;
            }

            $status = RedirectStatus::fromRow($row);

            // A rejected request never served and holds nothing.
            if ($status === RedirectStatus::Rejected) {
                continue;
            }

            if ($status === RedirectStatus::Pending) {
                return true;
            }

            // An approved redirect holds the back-half until the buffer after its last day has passed.
            $until = ValidityWindow::fromRow($row)->withReuseBuffer(RedirectHosts::REUSE_BUFFER_WEEKS)->until;

            if (! $until instanceof CarbonImmutable || ! $now->gt($until)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The reserved words, lowercased.
     *
     * @return list<string>
     */
    public static function reserved(): array
    {
        $configured = config(self::RESERVED_KEY);

        if (! \is_array($configured)) {
            return [];
        }

        $words = [];

        foreach ($configured as $word) {
            if (\is_string($word) && trim($word) !== '') {
                $words[] = strtolower(trim($word));
            }
        }

        return $words;
    }
}
