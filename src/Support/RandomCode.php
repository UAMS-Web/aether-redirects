<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Support;

use Closure;
use InvalidArgumentException;
use UAMSWeb\Aether\Redirects\Exceptions\CodeGenerationException;

/**
 * Draws random codes from an alphabet, redrawing while a draw is already taken.
 *
 * This is the one helper both generated code formats use (UAMS-Web/aether-redirects#15
 * and UAMS-Web/aether-redirects#84): a format is only an alphabet, a length and a
 * bound on attempts, and the caller supplies the check that says whether a draw
 * is taken. Each character is drawn uniformly with `random_int()`, a
 * cryptographically secure source, so a code cannot be predicted from earlier
 * ones.
 *
 * The helper has no I/O of its own: what counts as taken (case, validity
 * windows, which rows) belongs to the caller's check.
 */
final readonly class RandomCode
{
    /**
     * @param  string  $alphabet  The characters a code may contain, each once, single-byte
     * @param  int  $length  How many characters a code has
     * @param  int  $maxAttempts  How many draws to try before giving up
     *
     * @throws InvalidArgumentException When the alphabet, length or bound cannot produce a code
     */
    public function __construct(
        public string $alphabet,
        public int $length,
        public int $maxAttempts,
    ) {
        // A one-character alphabet can only ever produce one code.
        if (\strlen($alphabet) < 2) {
            throw new InvalidArgumentException('A code alphabet needs at least two characters.');
        }

        // A repeated character would be drawn more often than the others.
        if (\count(array_unique(str_split($alphabet))) !== \strlen($alphabet)) {
            throw new InvalidArgumentException('A code alphabet must not repeat a character.');
        }

        // An empty code is never a usable path.
        if ($length < 1) {
            throw new InvalidArgumentException('A code needs at least one character.');
        }

        // With no attempts allowed, every call would fail.
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('A code generator needs at least one attempt.');
        }
    }

    /**
     * Draw codes until one is not taken, and return it.
     *
     * @param  Closure(string): bool  $isTaken  Whether a drawn code is already in use
     * @return string A code the check reported free
     *
     * @throws CodeGenerationException When every allowed draw was taken
     */
    public function generate(Closure $isTaken): string
    {
        // Try up to the bound, returning the first free draw.
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $code = $this->draw();

            if (! $isTaken($code)) {
                return $code;
            }
        }

        // Every draw collided: stop rather than loop, with a message the requester can read.
        throw CodeGenerationException::exhausted($this->maxAttempts);
    }

    /**
     * Draw one code without checking whether it is taken.
     *
     * @return string A code of the configured length from the configured alphabet
     */
    public function draw(): string
    {
        // The highest valid index into the alphabet.
        $last = \strlen($this->alphabet) - 1;

        // Pick each character independently and uniformly.
        $code = '';

        for ($position = 0; $position < $this->length; $position++) {
            $code .= $this->alphabet[random_int(0, $last)];
        }

        return $code;
    }
}
