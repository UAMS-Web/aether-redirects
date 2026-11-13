<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainRules;
use UAMSWeb\Aether\Redirects\Support\RedirectSource;
use UAMSWeb\Aether\Redirects\Support\RedirectStatus;

/**
 * Reports the redirect domain rules whose sources differ only in case
 * (UAMS-Web/aether-redirects#17).
 *
 * A request on a redirect domain matches a source without regard to case, so two rules
 * whose sources differ only in case now claim the same request and the first in order
 * wins. Run this before relying on case-insensitive back-halves, and resolve each pair
 * it names so the rule that serves is the one intended. It reads only; it changes
 * nothing, and it exits non-zero when it finds a pair so a script can stop on it.
 *
 * A rule that does not serve is not counted: a disabled rule, and a rejected request.
 * A pattern source (a regular expression or a trailing wildcard) is not an exact
 * back-half, so it is left out.
 */
#[Description('Report the redirect domain rules whose sources differ only in case (UAMS-Web/aether-redirects#17)')]
#[Signature('redirects:report-case-duplicates')]
class ReportCaseDuplicateSources extends Command
{
    /**
     * Print each host's sources that differ only in case, and return a failure when there are any.
     */
    public function handle(RedirectDomainRules $domainRules): int
    {
        $found = 0;

        foreach ($domainRules->read()['rules'] as $host => $rules) {
            foreach ($this->groupsDifferingInCase($rules) as $spellings) {
                $this->line(sprintf('%s: %s', $host, implode(', ', $spellings)));
                $found++;
            }
        }

        if ($found === 0) {
            $this->info('No two sources differ only in case.');

            return self::SUCCESS;
        }

        $this->error(sprintf('%d source(s) differ only in case. Resolve each before relying on case-insensitive matching.', $found));

        return self::FAILURE;
    }

    /**
     * The spellings of each source that more than one rule writes in a different case.
     *
     * @param  list<array<string, mixed>>  $rules  One host's rules.
     * @return array<string, list<string>> The distinct spellings of each such source, keyed by its lowercase form, in the order first met.
     */
    private function groupsDifferingInCase(array $rules): array
    {
        /** @var array<string, list<string>> $byKey */
        $byKey = [];

        foreach ($rules as $rule) {
            $source = $rule['source'] ?? null;

            if (! \is_string($source) || ! $this->isExactAndServing($rule, $source)) {
                continue;
            }

            $spelling = RedirectSource::normalize($source);
            $key = strtolower($spelling);

            // Spellings are strings, which compare the same loosely or strictly
            // @pest-mutate-ignore:TrueToFalse
            if (! \in_array($spelling, $byKey[$key] ?? [], true)) {
                $byKey[$key][] = $spelling;
            }
        }

        return array_filter($byKey, static fn (array $spellings): bool => \count($spellings) > 1);
    }

    /**
     * Whether a rule is an exact source that can serve.
     *
     * @param  array<string, mixed>  $rule  The rule.
     * @param  string  $source  The rule's stored source.
     */
    private function isExactAndServing(array $rule, string $source): bool
    {
        // The reader always sets `enabled`, so the default is never used
        // @pest-mutate-ignore:TrueToFalse
        if (($rule['enabled'] ?? true) === false || RedirectStatus::fromRow($rule) === RedirectStatus::Rejected) {
            return false;
        }

        // The reader always sets `use_regex`, so the default is never used
        // @pest-mutate-ignore:FalseToTrue
        return ($rule['use_regex'] ?? false) !== true && ! str_ends_with($source, '*');
    }
}
