<?php

declare(strict_types=1);

use UAMSWeb\Aether\Redirects\Support\TaggedUrl;

covers(TaggedUrl::class);

/**
 * Unit tests for {@see TaggedUrl}, tagged URL assembly (UAMS-Web/aether-redirects#26).
 *
 * @covers \UAMSWeb\Aether\Redirects\Support\TaggedUrl
 *
 * @command  vendor/bin/pest tests/Unit/Support/TaggedUrlTest.php
 */
it('adds the parameters in the order given', function (): void {
    $url = TaggedUrl::build('https://uams.edu/flu', [
        ['utm_source', 'facebook'],
        ['utm_medium', 'social'],
        ['org_unit', 'com'],
    ]);

    expect($url)->toBe('https://uams.edu/flu?utm_source=facebook&utm_medium=social&org_unit=com');
});

it('encodes names and values, so a space or an ampersand yields a valid URL', function (): void {
    $url = TaggedUrl::build('https://uams.edu/', [['utm_campaign', 'flu & covid 2026'], ['x y', 'a=b/c']]);

    expect($url)->toBe('https://uams.edu/?utm_campaign=flu%20%26%20covid%202026&x%20y=a%3Db%2Fc')
        ->and(filter_var($url, FILTER_VALIDATE_URL))->toBe($url);

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($query)->toBe(['utm_campaign' => 'flu & covid 2026', 'x_y' => 'a=b/c']);
});

it('writes a parameter whose name is all digits', function (): void {
    expect(TaggedUrl::build('https://uams.edu/', [['123', 'x'], ['utm_source', 'web']]))
        ->toBe('https://uams.edu/?123=x&utm_source=web');
});

it('leaves out a parameter with an empty value', function (): void {
    expect(TaggedUrl::build('https://uams.edu/', [['utm_term', ''], ['utm_source', 'web'], ['qr', '0']]))
        ->toBe('https://uams.edu/?utm_source=web&qr=0');
});

it('returns the destination unchanged when nothing is written', function (): void {
    expect(TaggedUrl::build('https://uams.edu/flu', []))->toBe('https://uams.edu/flu')
        ->and(TaggedUrl::build('https://uams.edu/flu#top', [['utm_term', '']]))->toBe('https://uams.edu/flu#top');
});

it('keeps an existing query and adds after it', function (): void {
    expect(TaggedUrl::build('https://uams.edu/find?q=flu&page=2', [['utm_source', 'web']]))
        ->toBe('https://uams.edu/find?q=flu&page=2&utm_source=web');
});

it('replaces a parameter the destination already carries instead of repeating it', function (): void {
    expect(TaggedUrl::build('https://uams.edu/?utm_source=old&q=flu&utm%5Fmedium=x', [['utm_source', 'new'], ['utm_medium', 'social']]))
        ->toBe('https://uams.edu/?q=flu&utm_source=new&utm_medium=social');
});

it('keeps the fragment at the end', function (): void {
    expect(TaggedUrl::build('https://uams.edu/flu?q=1#clinics', [['utm_source', 'web']]))
        ->toBe('https://uams.edu/flu?q=1&utm_source=web#clinics')
        ->and(TaggedUrl::build('https://uams.edu/flu#a#b', [['utm_source', 'web']]))
        ->toBe('https://uams.edu/flu?utm_source=web#a#b');
});

it('treats a bare question mark as an empty query', function (): void {
    expect(TaggedUrl::build('https://uams.edu/flu?', [['utm_source', 'web']]))->toBe('https://uams.edu/flu?utm_source=web')
        ->and(TaggedUrl::build('https://uams.edu/flu?', []))->toBe('https://uams.edu/flu');
});

it('leaves every utm_* parameter out when suppressed, and keeps the others', function (): void {
    $parameters = [['utm_source', 'facebook'], ['org_unit', 'com'], ['utm_campaign', 'flu'], ['vanity', 'flu']];

    expect(TaggedUrl::build('https://uams.edu/flu', $parameters, suppressUtm: true))
        ->toBe('https://uams.edu/flu?org_unit=com&vanity=flu')
        ->and(TaggedUrl::build('https://uams.edu/flu', $parameters))
        ->toBe('https://uams.edu/flu?utm_source=facebook&org_unit=com&utm_campaign=flu&vanity=flu');
});

it('does not remove an existing utm_* pair from the destination when suppressed', function (): void {
    expect(TaggedUrl::build('https://uams.edu/flu?utm_source=keep', [['utm_source', 'web']], suppressUtm: true))
        ->toBe('https://uams.edu/flu?utm_source=keep');
});

it('appends a custom value with an underscore and lowercases the result', function (): void {
    expect(TaggedUrl::value('Facebook', 'Spring Push'))->toBe('facebook_spring push')
        ->and(TaggedUrl::value('ÉTÉ', 'X'))->toBe('été_x')
        ->and(TaggedUrl::value('Facebook', '  Ads  '))->toBe('facebook_ads');
});

it('uses the list value as it stands with no custom value', function (?string $custom): void {
    expect(TaggedUrl::value('Facebook', $custom))->toBe('Facebook');
})->with(['null' => [null], 'empty' => [''], 'whitespace' => ['   ']]);
