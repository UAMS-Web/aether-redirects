<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Support;

/**
 * Ports Safe Redirect Manager's pattern-matching semantics onto the `redirects`
 * global set.
 *
 * The `redirects` store is a near-1:1 port of the WordPress Safe Redirect Manager
 * (SRM) schema, and the importer writes SRM rules into it verbatim. SRM matches a
 * stored source three ways — literally, by a trailing-`*` wildcard, and by PCRE —
 * and this class carries the two pattern forms so a rule imported from WordPress
 * fires here exactly as it did there. The literal form stays in the serving tag.
 *
 * Three behaviors are faithful ports rather than reinventions, and each one is
 * load-bearing:
 *   - A wildcard base keeps its trailing slash, so `/foo/*` matches `/foo` itself
 *     as well as everything beneath it, and never matches `/foobar`.
 *   - The remainder appended to a `*`-terminated target is read from the
 *     original-case URI, while the match that found it is case-insensitive.
 *   - Case folding is byte-wise, matching SRM's `strtolower()`. This class does
 *     offset arithmetic against the un-folded URI, and only a byte-length-stable
 *     fold keeps those offsets aligned; multibyte folding does not.
 *
 * One behavior deliberately diverges. SRM skips capture substitution whenever the
 * target passes `filter_var($target, FILTER_VALIDATE_URL)`, so an absolute-URL
 * target redirects to a literal `$1`. That is a defect rather than a contract —
 * 76 of the 508 capture-carrying rules in the export corpus are broken by it in
 * WordPress today — so this port substitutes unconditionally.
 */
final class RedirectPattern
{
    /**
     * The token that marks a source or target as a wildcard, in SRM's syntax.
     */
    private const string WILDCARD = '*';

    /**
     * Delimiters tried, in order, when wrapping a bare SRM pattern. The first one
     * absent from the pattern is used; SRM itself always wraps in `@`.
     *
     * @var list<string>
     */
    // PCOV attributes no coverage to a constant's initializer, so Pest selects no test for its mutants and
    // reports them uncovered. A changed value is observable, and the delimiter dataset in `RedirectPatternTest` pins it.
    // @pest-mutate-ignore:RemoveArrayItem
    private const array BARE_PATTERN_DELIMITERS = ['@', '#', '~', '!', '%', '|'];

    /**
     * Modifiers applied to a wrapped bare pattern. SRM appends `i` whenever its
     * `uamswp_srm_case_insensitive_redirects` filter is on, which it is by default.
     */
    private const string BARE_PATTERN_MODIFIERS = 'i';

    /**
     * Whether a stored source or target uses SRM's trailing-`*` wildcard syntax.
     *
     * @param  string  $value  The stored source or target
     * @return bool True when the value's final character is the wildcard token
     */
    public static function isWildcard(string $value): bool
    {
        // A lone `*` counts; an empty string ends with nothing and does not.
        return str_ends_with($value, self::WILDCARD);
    }

    /**
     * The base of a wildcard source — everything ahead of the trailing `*`.
     *
     * The base deliberately keeps any trailing slash it was stored with, because
     * that slash is what makes `/foo/*` match `/foo/bar` but not `/foobar`.
     *
     * @param  string  $source  The wildcard source
     * @return string The source with its trailing wildcard token removed
     */
    public static function base(string $source): string
    {
        // Drop only the final character; a `*` earlier in the source is literal.
        return substr($source, 0, -1);
    }

    /**
     * Whether a request URI falls under a wildcard source.
     *
     * Ports SRM's comparison exactly: the URI gains a trailing slash before the
     * prefix test, so a request for the base path itself matches the wildcard.
     *
     * @param  string  $source  The stored wildcard source (trailing `*` included)
     * @param  string  $uri  The request URI to test
     * @return bool True when the URI is the base path or sits beneath it
     */
    public static function matchesWildcard(string $source, string $uri): bool
    {
        // Reject anything that is not actually a wildcard source.
        if (! self::isWildcard($source)) {
            return false;
        }

        // Fold the base for a case-insensitive comparison, as SRM does.
        $base = strtolower(self::base($source));

        // Give the URI a trailing slash so the base path itself matches, then
        // fold it the same way before the prefix test. The trailing separators
        // are stripped the way WordPress's trailingslashit() strips them, which
        // takes a backslash as well as a slash.
        $candidate = strtolower(rtrim($uri, '/\\').'/');

        // The URI matches when it opens with the wildcard base. A bare `*` has an
        // empty base, which every candidate opens with, so it matches everything.
        return str_starts_with($candidate, $base);
    }

    /**
     * Append a wildcard match's remainder to a `*`-terminated target.
     *
     * A target that does not end in `*` is used verbatim — SRM sends every request
     * under such a rule to one fixed destination. When the target does end in `*`,
     * the part of the URI beyond the source's base is appended in its original
     * case, so `/foo/*` → `/bar/*` sends `/foo/Baz` to `/bar/Baz`.
     *
     * @param  string  $source  The stored wildcard source (trailing `*` included)
     * @param  string  $target  The stored target, which may end in `*`
     * @param  string  $uri  The request URI that matched the source
     * @return string The target with the matched remainder appended when applicable
     */
    public static function expandTarget(string $source, string $target, string $uri): string
    {
        // Leave a fixed destination untouched.
        if (! self::isWildcard($target)) {
            return $target;
        }

        // Measure the remainder against the un-folded base so byte offsets align
        // with the un-folded URI this reads from.
        $base = self::base($source);

        // Take everything past the base, dropping the separator the target carries.
        $remainder = ltrim(substr($uri, \strlen($base)), '/');

        // Strip every trailing wildcard token from the target, then append.
        return rtrim($target, self::WILDCARD).$remainder;
    }

    /**
     * Compile a stored regex source into a pattern `preg_match()` can run.
     *
     * SRM stores a *bare* pattern and supplies the delimiters itself at match time,
     * so an imported source reads `^\/legacy\/(.+)` rather than `/^\/legacy\/(.+)/`.
     * Passing that to `preg_match()` unmodified makes PHP read `/` as the delimiter
     * and the rest as modifiers, which fails to compile — every imported regex rule
     * errors instead of matching.
     *
     * A source that already compiles as a delimited pattern is honored verbatim, so
     * a Control-Panel-authored PCRE keeps its own delimiters and modifiers. Anything
     * else is treated as a bare SRM pattern and wrapped. The two forms cannot be
     * confused in practice: none of the 911 regex sources in the export corpus
     * compiles as a delimited pattern.
     *
     * @param  string  $source  The stored regex source
     * @return string|null A runnable pattern, or null when the source cannot compile either way
     */
    public static function compile(string $source): ?string
    {
        // An empty source can never match anything.
        if ($source === '') {
            return null;
        }

        // Honor a source that is already a valid delimited pattern.
        if (self::compiles($source)) {
            return $source;
        }

        // Pick a delimiter the bare pattern does not itself contain.
        $delimiter = self::freeDelimiter($source);

        // Give up when every candidate delimiter appears in the pattern. Appending the modifiers
        // cannot make a source that failed to compile as delimited compile: the failure is in the
        // delimiters or the pattern, never in a missing modifier, so with the early return removed
        // the same null comes back from the check below.
        // @pest-mutate-ignore:RemoveEarlyReturn
        if ($delimiter === null) {
            return null;
        }

        // Wrap the bare pattern the way SRM does, case-insensitively.
        $wrapped = $delimiter.$source.$delimiter.self::BARE_PATTERN_MODIFIERS;

        // Only hand back a wrapped pattern that actually compiles.
        return self::compiles($wrapped) ? $wrapped : null;
    }

    /**
     * Hold a substituted target to the scope the stored target was authored in.
     *
     * This is SRM's own post-substitution normalization — `'/'.ltrim($target, '/')`
     * — applied only where SRM applies it, to a target that named no scheme or
     * authority of its own. It carries a security property worth stating, because
     * capture substitution is what makes it load-bearing here: substitution folds
     * request-derived text into the target, so a crafted request can push a stored
     * *relative* target to a protocol-relative one (`//evil.test`) and turn the rule
     * into an open redirect. Collapsing the leading slash run defeats that, while an
     * already-absolute target is the author's explicit choice and is left alone.
     *
     * @param  string  $storedTarget  The target as stored on the redirect rule
     * @param  string  $substituted  The target after capture substitution
     * @return string The substituted target, constrained to the stored target's scope
     */
    public static function constrainToStoredScope(string $storedTarget, string $substituted): string
    {
        // An author-chosen absolute or protocol-relative target keeps its scope.
        if (self::hasAuthority($storedTarget)) {
            return $substituted;
        }

        // An empty result has no scope to constrain.
        if ($substituted === '') {
            return $substituted;
        }

        // Force exactly one leading slash, which both normalizes a relative result
        // and collapses an authority a crafted request tried to inject.
        return '/'.ltrim($substituted, '/');
    }

    /**
     * Whether a target names a scheme or an authority of its own.
     *
     * @param  string  $target  The stored target
     * @return bool True for an absolute or protocol-relative target
     */
    private static function hasAuthority(string $target): bool
    {
        // A protocol-relative target carries an authority without a scheme.
        if (str_starts_with($target, '//')) {
            return true;
        }

        // Otherwise look for a scheme ahead of the first path separator.
        return preg_match('#\A[a-zA-Z][a-zA-Z0-9+.-]*:#', $target) === 1;
    }

    /**
     * The first candidate delimiter that does not appear in the pattern.
     *
     * @param  string  $pattern  The bare pattern about to be wrapped
     * @return string|null The usable delimiter, or null when every candidate collides
     */
    private static function freeDelimiter(string $pattern): ?string
    {
        // Walk the candidates in order and take the first one that is free.
        foreach (self::BARE_PATTERN_DELIMITERS as $delimiter) {
            if (! str_contains($pattern, $delimiter)) {
                return $delimiter;
            }
        }

        return null;
    }

    /**
     * Whether a pattern compiles, without surfacing PHP's warning for one that does not.
     *
     * @param  string  $pattern  The candidate pattern
     * @return bool True when PCRE accepts the pattern
     */
    private static function compiles(string $pattern): bool
    {
        // Swallow the compile warning an invalid pattern raises; the return value
        // is the signal this reads.
        set_error_handler(static fn (): bool => true);

        try {
            // An empty subject is enough to force compilation without doing work. Compilation
            // does not read the subject, so any other string reaches the same verdict, short of a match-time limit
            // error that a pattern which compiles would have to be built to raise.
            // @pest-mutate-ignore:EmptyStringToNotEmpty
            return preg_match($pattern, '') !== false;
        } finally {
            // Always hand the previous handler back.
            restore_error_handler();
        }
    }
}
