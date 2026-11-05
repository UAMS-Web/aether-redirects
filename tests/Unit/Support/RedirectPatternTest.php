<?php

declare(strict_types=1);

use UAMSWeb\Aether\Redirects\Support\RedirectPattern;

/**
 * Unit tests for the Safe Redirect Manager pattern port (UAMS-Web/uams-statamic-archive#1933).
 *
 * Pins the two pattern forms the `redirects` store inherits from SRM: the
 * trailing-`*` wildcard (match, base handling, and remainder append) and the
 * bare-vs-delimited PCRE compilation that makes an imported regex source runnable
 * at all. The wildcard cases are written against SRM's own comparison — base keeps
 * its trailing slash, the URI gains one before the prefix test, and the appended
 * remainder is read in the request's original case.
 *
 * Mutation: 97.22% on UAMSWeb\Aether\Redirects\Support\RedirectPattern (72 mutants,
 * --covered-only). Both survivors sit in the private compiles() probe and are
 * left deliberately. `preg_match($pattern, '')` -> a non-empty subject is a true
 * equivalent mutant: the subject cannot change whether a pattern *compiles*.
 * `set_error_handler(fn () => true)` -> `false` is not equivalent — returning
 * false lets PHP's internal handler run, so the compile warning reaches the
 * error log — but it is unobservable from the caller, because a user-defined
 * handler replaces rather than chains, so the outer handler never sees it either
 * way. That
 * is exactly what the two probe tests below assert, so neither can kill it.
 *
 * @command  vendor/bin/pest tests/Unit/Support/RedirectPatternTest.php
 */
it('recognizes a trailing-wildcard source', function (string $value, bool $expected): void {
    expect(RedirectPattern::isWildcard($value))->toBe($expected);
})->with([
    'plain path' => ['/foo', false],
    'trailing wildcard' => ['/foo/*', true],
    'bare wildcard' => ['*', true],
    'interior wildcard only' => ['/foo/*/bar', false],
    'empty string' => ['', false],
]);

it('keeps the trailing slash on a wildcard base', function (): void {
    // The slash is what makes `/foo/*` match `/foo/bar` but never `/foobar`.
    expect(RedirectPattern::base('/foo/*'))->toBe('/foo/')
        ->and(RedirectPattern::base('/foo*'))->toBe('/foo')
        ->and(RedirectPattern::base('*'))->toBe('');
});

it('matches the base path itself and everything beneath it', function (string $uri, bool $expected): void {
    expect(RedirectPattern::matchesWildcard('/committees/*', $uri))->toBe($expected);
})->with([
    'the base path itself' => ['/committees', true],
    'the base path with a slash' => ['/committees/', true],
    'a child path' => ['/committees/research', true],
    'a deep child path' => ['/committees/research/2024', true],
    'a sibling sharing the prefix' => ['/committees-archive', false],
    'an unrelated path' => ['/officers', false],
    'the root' => ['/', false],
]);

it('matches a wildcard source without regard to case', function (): void {
    expect(RedirectPattern::matchesWildcard('/Committees/*', '/committees/research'))->toBeTrue()
        ->and(RedirectPattern::matchesWildcard('/committees/*', '/COMMITTEES/Research'))->toBeTrue();
});

it('treats a bare wildcard as matching every request', function (): void {
    expect(RedirectPattern::matchesWildcard('*', '/anything/at/all'))->toBeTrue();
});

it('strips a trailing backslash from the uri before the prefix test', function (): void {
    // WordPress's trailingslashit() strips a backslash as well as a slash, so a
    // request ending in one still resolves the wildcard rather than missing it.
    expect(RedirectPattern::matchesWildcard('/committees/*', '/committees\\'))->toBeTrue();
});

it('refuses to match a source that carries no wildcard', function (): void {
    expect(RedirectPattern::matchesWildcard('/committees', '/committees/research'))->toBeFalse();
});

it('appends the matched remainder to a wildcard target', function (): void {
    expect(RedirectPattern::expandTarget('/academic-senate-committees/*', '/committees/*', '/academic-senate-committees/research'))
        ->toBe('/committees/research');
});

it('appends nothing when the request is the base path itself', function (): void {
    expect(RedirectPattern::expandTarget('/academic-senate-committees/*', '/committees/*', '/academic-senate-committees'))
        ->toBe('/committees/');
});

it('preserves the original case of the appended remainder', function (): void {
    // Matching folds case; the remainder must not, or the destination 404s.
    expect(RedirectPattern::expandTarget('/Old/*', '/new/*', '/old/MixedCase'))
        ->toBe('/new/MixedCase');
});

it('does not double the separator when the remainder opens with a slash', function (): void {
    // The base already carries the separator, so a doubled slash in the request
    // must not survive into the destination.
    expect(RedirectPattern::expandTarget('/foo/*', '/new/*', '/foo//bar'))
        ->toBe('/new/bar');
});

it('sends every request to a fixed target that carries no wildcard', function (): void {
    expect(RedirectPattern::expandTarget('/old/*', '/new-home', '/old/deep/path'))
        ->toBe('/new-home');
});

it('expands a wildcard target on an absolute url', function (): void {
    expect(RedirectPattern::expandTarget('/old/*', 'https://example.test/new/*', '/old/thing'))
        ->toBe('https://example.test/new/thing');
});

it('compiles a bare Safe Redirect Manager pattern by wrapping it', function (): void {
    $compiled = RedirectPattern::compile('^\/legacy\/(.+)');

    expect($compiled)->toBe('@^\/legacy\/(.+)@i')
        ->and(preg_match((string) $compiled, '/legacy/thing'))->toBe(1);
});

it('honors a Control Panel pattern that already carries its own delimiters', function (): void {
    // A delimited source is used verbatim so an author keeps their modifiers.
    expect(RedirectPattern::compile('/^\/blog\/\d{4}\/.+$/'))->toBe('/^\/blog\/\d{4}\/.+$/');
});

it('makes a bare pattern case-insensitive, matching SRM', function (): void {
    $compiled = RedirectPattern::compile('^\/Legacy\/(.+)');

    expect(preg_match((string) $compiled, '/legacy/thing'))->toBe(1);
});

it('picks a delimiter the bare pattern does not itself contain', function (): void {
    // `@` is taken, so the next free candidate is used instead of corrupting it.
    expect(RedirectPattern::compile('^/mail@example/(.+)'))->toBe('#^/mail@example/(.+)#i');
});

it('refuses a source that cannot compile either way', function (string $source): void {
    expect(RedirectPattern::compile($source))->toBeNull();
})->with([
    'empty' => [''],
    'unclosed group' => ['^/broken/(.+'],
]);

it('surfaces no warning while probing a pattern that will not compile', function (): void {
    // Probing validity means running an invalid pattern through preg_match on
    // purpose. The warning that raises must stay inside the probe rather than
    // reaching the caller's handler.
    $warnings = [];

    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    });

    try {
        $result = RedirectPattern::compile('^/broken/(.+');
    } finally {
        restore_error_handler();
    }

    expect($result)->toBeNull()
        ->and($warnings)->toBe([]);
});

it('restores the caller error handler after probing a pattern', function (): void {
    // A probe that left its own swallow-everything handler installed would hide
    // every warning raised for the rest of the process.
    $sentinel = static fn (): bool => false;

    set_error_handler($sentinel);

    RedirectPattern::compile('^/broken/(.+');

    // Reading the active handler requires installing one, so put it straight back.
    $active = set_error_handler(null);
    restore_error_handler();
    restore_error_handler();

    expect($active)->toBe($sentinel);
});

it('does not mistake a bare pattern for a delimited one', function (): void {
    // `/legacy/(.*)` reads as delimiter `/`, pattern `legacy`, modifiers `(.*)`,
    // which is exactly the compile failure that leaves imported rules dead.
    $compiled = RedirectPattern::compile('/legacy/(.*)');

    expect($compiled)->toBe('@/legacy/(.*)@i')
        ->and(preg_match((string) $compiled, '/legacy/thing'))->toBe(1);
});

it('holds a substituted relative target to a site-relative path', function (string $stored, string $substituted, string $expected): void {
    expect(RedirectPattern::constrainToStoredScope($stored, $substituted))->toBe($expected);
})->with([
    'already rooted' => ['/new/$1', '/new/thing', '/new/thing'],
    'missing leading slash' => ['new/$1', 'new/thing', '/new/thing'],
    'injected authority collapsed' => ['/new/$1', '//evil.test/x', '/evil.test/x'],
    'empty result untouched' => ['/new/$1', '', ''],
]);

it('leaves an author-chosen absolute target at its own scope', function (string $stored): void {
    expect(RedirectPattern::constrainToStoredScope($stored, '//evil.test/x'))->toBe('//evil.test/x');
})->with([
    'absolute https target' => ['https://example.test/$1'],
    'protocol-relative target' => ['//example.test/$1'],
    'mailto scheme' => ['mailto:someone@example.test'],
]);
