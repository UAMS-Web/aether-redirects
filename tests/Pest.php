<?php

/**
 * Pest configuration for the add-on.
 *
 * Integration tests boot a minimal Statamic through Orchestra Testbench via
 * `Tests\TestCase`; Unit tests stay on PHPUnit's own base class and boot
 * nothing. Shared helpers and custom expectations go here when the suite
 * grows them.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use UAMSWeb\Aether\Redirects\Tests\TestCase;

pest()->extend(TestCase::class)->in('Integration');

/**
 * The output of the last Artisan command with every line ending as `\n`.
 *
 * Console output carries the platform's line ending (`\r\n` on Windows), so a test that
 * compares it with text containing `\n` reads it through here
 * (UAMS-Web/aether-redirects#116).
 */
function consoleOutput(): string
{
    return str_replace("\r\n", "\n", Artisan::output());
}
