<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Exceptions;

use RuntimeException;
use UAMSWeb\Aether\Redirects\Support\RandomCode;

/**
 * Raised when {@see RandomCode} cannot draw a code that is not already taken
 * within its bounded number of attempts (UAMS-Web/aether-redirects#15).
 *
 * The message is written for the person requesting the code, so a caller can
 * show it as it stands rather than looping or failing silently.
 */
final class CodeGenerationException extends RuntimeException
{
    /**
     * Build the exception for a generator that used every attempt it was allowed.
     *
     * @param  int  $attempts  How many draws were tried, every one already taken
     * @return self The exception, carrying a message the requester can read
     */
    public static function exhausted(int $attempts): self
    {
        return new self(sprintf(
            'A unique code could not be generated after %d attempts. Try again, and report it if this keeps happening.',
            $attempts,
        ));
    }
}
