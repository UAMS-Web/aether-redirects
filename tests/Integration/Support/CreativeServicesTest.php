<?php

declare(strict_types=1);

use UAMSWeb\Aether\Redirects\Support\CreativeServices;

covers(CreativeServices::class);

/**
 * Integration tests for {@see CreativeServices}, the answer and job number every request carries
 * (UAMS-Web/aether-redirects#25). They need the configuration repository, so they boot the application.
 *
 * @covers \UAMSWeb\Aether\Redirects\Support\CreativeServices
 *
 * @command  vendor/bin/pest tests/Integration/Support/CreativeServicesTest.php
 */
it('offers the yes and no answers, and counts only yes as involved', function (): void {
    expect(CreativeServices::answers())->toBe(['yes', 'no'])
        ->and(CreativeServices::involved('yes'))->toBeTrue()
        ->and(CreativeServices::involved('no'))->toBeFalse()
        ->and(CreativeServices::involved(null))->toBeFalse();
});

it('accepts any non-blank job number while no pattern is set, which is the default', function (): void {
    expect(config('aether-redirects.creative_services.job_number.pattern'))->toBeNull()
        ->and(CreativeServices::jobNumberError('anything 9'))->toBeNull();
});

it('refuses a missing or blank job number', function (?string $number): void {
    expect(CreativeServices::jobNumberError($number))->toBe('Enter the Creative Services job number.');
})->with([
    'null' => [null],
    'empty' => [''],
    'spaces' => ['   '],
]);

it('checks a job number against the configured pattern and names the format in words', function (): void {
    config([
        'aether-redirects.creative_services.job_number.pattern' => '/^\d{5}$/',
        'aether-redirects.creative_services.job_number.description' => 'five digits',
    ]);

    expect(CreativeServices::jobNumberError('12345'))->toBeNull()
        ->and(CreativeServices::jobNumberError(' 12345 '))->toBeNull()
        ->and(CreativeServices::jobNumberError('1234'))->toBe('The job number must match five digits.')
        ->and(CreativeServices::jobNumberError('12345x'))->toBe('The job number must match five digits.')
        ->and(CreativeServices::description())->toBe('five digits');
});

it('describes the format generically when no description is set', function (?string $configured): void {
    config(['aether-redirects.creative_services.job_number.description' => $configured]);

    expect(CreativeServices::description())->toBe('the Creative Services job number');
})->with([
    'null' => [null],
    'blank' => ['   '],
]);

it('refuses to check against a pattern that is not a regular expression', function (): void {
    config(['aether-redirects.creative_services.job_number.pattern' => '/unclosed[']);

    expect(fn (): ?string => CreativeServices::jobNumberError('12345'))
        ->toThrow(LogicException::class, 'is not a valid regular expression');
});

it('treats an empty pattern as no pattern', function (): void {
    config(['aether-redirects.creative_services.job_number.pattern' => '']);

    expect(CreativeServices::jobNumberError('anything'))->toBeNull();
});

it('trims a configured description', function (): void {
    config(['aether-redirects.creative_services.job_number.description' => '  five digits  ']);

    expect(CreativeServices::description())->toBe('five digits');
});
