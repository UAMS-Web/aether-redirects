<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use UAMSWeb\Aether\Redirects\Support\TypedBackHalf;

covers(TypedBackHalf::class);

/**
 * Integration tests for {@see TypedBackHalf}, the rules a back-half the requester types must pass
 * (UAMS-Web/aether-redirects#16). They need the configuration repository and the translator, so they boot the application.
 *
 * @covers \UAMSWeb\Aether\Redirects\Support\TypedBackHalf
 *
 * @command  vendor/bin/pest tests/Integration/Support/TypedBackHalfTest.php
 */
const TYPED_BACK_HALF_FORM = 'A back-half uses only letters, numbers and hyphens, and starts and ends with a letter or number.';

afterEach(function (): void {
    Carbon::setTestNow();
});

it('accepts a back-half of letters, numbers and hyphens, with or without its slash', function (string $source): void {
    expect(TypedBackHalf::error($source, []))->toBeNull();
})->with([
    'a word' => ['hotdog'],
    'with a leading slash' => ['/hotdog'],
    'with surrounding space' => ['  hotdog  '],
    'one letter' => ['a'],
    'one number' => ['9'],
    'mixed case' => ['HotDog'],
    'with a hyphen' => ['flu-shot'],
    'with a run of hyphens' => ['flu--shot'],
    'a number then letters' => ['2026flu'],
    'the longest allowed' => [str_repeat('a', TypedBackHalf::MAX_LENGTH)],
]);

it('refuses a back-half that is not letters, numbers and hyphens, or does not start and end with a letter or number', function (string $source): void {
    expect(TypedBackHalf::error($source, []))->toBe(TYPED_BACK_HALF_FORM);
})->with([
    'empty' => [''],
    'only slashes' => ['//'],
    'a leading hyphen' => ['-hotdog'],
    'a trailing hyphen' => ['hotdog-'],
    'only a hyphen' => ['-'],
    'a space inside' => ['hot dog'],
    'an underscore' => ['hot_dog'],
    'a dot' => ['hot.dog'],
    'a nested path' => ['hot/dog'],
    'a non-ASCII letter' => ['caf'."\u{e9}"],
    'a trailing newline inside' => ["hotdog\n-x"],
]);

it('limits the length and says what the limit is', function (): void {
    expect(TypedBackHalf::error(str_repeat('a', TypedBackHalf::MAX_LENGTH + 1), []))->toBe('A back-half is 50 characters or fewer.')
        ->and(TypedBackHalf::MAX_LENGTH)->toBe(50);
});

it('refuses a back-half ending in -QR in any case', function (string $source): void {
    expect(TypedBackHalf::error($source, []))->toBe('A back-half cannot end in -QR, which names the QR redirect of a vanity URL.');
})->with([
    'upper case' => ['hotdog-QR'],
    'lower case' => ['hotdog-qr'],
    'mixed case' => ['hotdog-Qr'],
    'with a slash' => ['/hotdog-qR'],
    'a generated-looking back-half' => ['aB3xY9q-QR'],
]);

it('accepts a back-half that only contains or nearly ends in QR', function (string $source): void {
    expect(TypedBackHalf::error($source, []))->toBeNull();
})->with([
    'qr alone' => ['qr'],
    'QR in the middle' => ['flu-QR-shot'],
    'a longer ending' => ['flu-QRS'],
    'no hyphen' => ['fluQR'],
]);

it('reserves nothing by default', function (): void {
    expect(config('aether-redirects.back_half.reserved'))->toBe([])
        ->and(TypedBackHalf::reserved())->toBe([])
        ->and(TypedBackHalf::error('admin', []))->toBeNull();
});

it('refuses a reserved word in any case and names it', function (string $source): void {
    config(['aether-redirects.back_half.reserved' => ['Admin', ' login ', '', '   ', 5]]);

    expect(TypedBackHalf::reserved())->toBe(['admin', 'login'])
        ->and(TypedBackHalf::error($source, []))->toBe('"'.trim($source, '/').'" is a reserved word and cannot be used as a back-half.');
})->with([
    'as configured' => ['Admin'],
    'lower case' => ['admin'],
    'upper case' => ['/LOGIN'],
]);

it('accepts a word that only contains a reserved one', function (): void {
    config(['aether-redirects.back_half.reserved' => ['admin']]);

    expect(TypedBackHalf::error('administrator', []))->toBeNull();
});

it('compares a reserved word as text, not as a number', function (): void {
    config(['aether-redirects.back_half.reserved' => ['10']]);

    expect(TypedBackHalf::error('1e1', []))->toBeNull()
        ->and(TypedBackHalf::error('10', []))->toContain('reserved word');
});

it('treats a reserved setting that is not a list as empty', function (): void {
    config(['aether-redirects.back_half.reserved' => 'admin']);

    expect(TypedBackHalf::reserved())->toBe([])
        ->and(TypedBackHalf::error('admin', []))->toBeNull();
});

it('refuses a back-half a pending request or an approved redirect holds, without regard to case', function (array $row): void {
    $rows = [['source' => '/Other'], $row];

    expect(TypedBackHalf::error('hotdog', $rows))->toBe('The back-half "hotdog" is already in use on this domain, in any case.')
        ->and(TypedBackHalf::error('/HOTDOG', $rows))->toBe('The back-half "HOTDOG" is already in use on this domain, in any case.');
})->with([
    'a pending request' => [['source' => '/HotDog', 'status' => 'pending']],
    'an approved redirect' => [['source' => '/hotDog', 'status' => 'approved']],
    'a legacy redirect with no status' => [['source' => '/hotdog']],
    'an approved redirect with no end date' => [['source' => '/hotdog', 'status' => 'approved', 'valid_from' => '2020-01-01']],
    'an approved redirect not yet started' => [['source' => '/hotdog', 'status' => 'approved', 'valid_from' => '2099-01-01', 'valid_until' => '2099-02-01']],
    'a pending request whose window has ended' => [['source' => '/hotdog', 'status' => 'pending', 'valid_from' => '2020-01-01', 'valid_until' => '2020-01-31']],
]);

it('lets a back-half through when only a rejected request, another source or a long-ended redirect has it', function (array $row): void {
    expect(TypedBackHalf::error('hotdog', [$row]))->toBeNull();
})->with([
    'a rejected request' => [['source' => '/hotdog', 'status' => 'rejected']],
    'another source' => [['source' => '/hotdog2', 'status' => 'pending']],
    'a longer source' => [['source' => '/hotdog/more', 'status' => 'approved']],
    'a redirect that ended long ago' => [['source' => '/hotdog', 'status' => 'approved', 'valid_from' => '2020-01-01', 'valid_until' => '2020-01-31']],
]);

it('skips rows that are not stored rows with a string source, and keeps looking', function (): void {
    expect(TypedBackHalf::error('hotdog', ['text', ['status' => 'pending'], ['source' => 5, 'status' => 'pending']]))->toBeNull()
        ->and(TypedBackHalf::error('hotdog', ['text', ['source' => 5], ['source' => '/hotdog', 'status' => 'pending']]))->toContain('already in use');
});

it('keeps looking after a rejected request', function (): void {
    expect(TypedBackHalf::error('hotdog', [['source' => '/hotdog', 'status' => 'rejected'], ['source' => '/hotdog', 'status' => 'pending']]))->toContain('already in use');
});

it('holds an approved redirect for 12 weeks after its last day, to the end of that day', function (string $until, bool $held): void {
    Carbon::setTestNow('2026-11-13 10:00:00');

    $error = TypedBackHalf::error('hotdog', [['source' => '/hotdog', 'status' => 'approved', 'valid_until' => $until]]);

    expect($error !== null)->toBe($held);
})->with([
    'the day the buffer ends' => ['2026-08-22', true],
    'the day before that' => ['2026-08-23', true],
    'the day after the buffer lapsed' => ['2026-08-21', false],
    'long before' => ['2026-01-01', false],
]);

it('reports the first rule that fails', function (): void {
    config(['aether-redirects.back_half.reserved' => ['admin-qr', 'admin']]);
    $rows = [['source' => '/admin-qr', 'status' => 'pending'], ['source' => '/admin', 'status' => 'pending']];

    expect(TypedBackHalf::error('admin qr', $rows))->toBe(TYPED_BACK_HALF_FORM)
        ->and(TypedBackHalf::error('admin-qr', $rows))->toContain('names the QR redirect')
        ->and(TypedBackHalf::error('admin', $rows))->toContain('reserved word');

    config(['aether-redirects.back_half.reserved' => []]);

    expect(TypedBackHalf::error('admin', $rows))->toContain('already in use');
});
