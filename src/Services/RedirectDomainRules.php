<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Services;

use Illuminate\Support\Collection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
use Statamic\Stache\Query\EntryQueryBuilder;

/**
 * Reads the `redirect_domains` collection into redirect rules grouped by host,
 * in the shape `EdgeRedirectManifestBuilder` reads from the `redirects` global set
 * (UAMS-Web/uams-statamic-archive#2678).
 *
 * UAMS-Web/uams-statamic-archive#2594 decided that redirects for domains that are not Statamic sites are
 * authored as entries, each carrying a host, and that the edge generator emits one
 * manifest per host. An entry is one of two kinds:
 *
 *   - a whole-domain entry, which becomes a single `/*` rule with a fixed target,
 *     so every path on the host goes to one URL with nothing appended; or
 *   - a source entry, which becomes the same rule a `redirects` global-set row
 *     with the same fields would be.
 *
 * An entry target is resolved here, to the entry's absolute URL at the moment the
 * rules are read. These domains never reach Statamic, so the serving template that
 * resolves an entry target for the main site never runs for them, and a relative
 * URL would stay on the redirecting domain. Reading the URL at generation time is
 * what lets a domain follow its destination when that entry moves: the next
 * manifest carries the new URL. A target entry that cannot be resolved, because it
 * is missing, unpublished or has no URL, is left as an entry target, which the
 * builder hands to the origin, and is counted so the command can report it.
 */
final readonly class RedirectDomainRules
{
    /**
     * The handle of the collection the rules are authored in.
     */
    public const string COLLECTION = 'redirect_domains';

    /**
     * The order a whole-domain rule is given, so it is tried after every source
     * rule on the same host.
     */
    public const int WHOLE_DOMAIN_ORDER = PHP_INT_MAX;

    /**
     * The fields that decide whether a rule serves at request time: its validity
     * window and its approval status. The blueprint does not offer them today; an
     * entry that carries them keeps them, so the engine reads them as it reads a
     * `redirects` global-set row.
     *
     * @var list<string>
     */
    // PCOV attributes no coverage to a constant's initializer, so Pest selects no test for its mutants and
    // reports them uncovered. A changed value is observable, and the validity window and approval status test in `RedirectDomainRulesTest` pins it.
    // @pest-mutate-ignore:RemoveArrayItem
    private const array GATE_FIELDS = ['valid_from', 'valid_until', 'status'];

    /**
     * A host as the collection's `host` field validates it: dot-separated labels
     * of letters, digits and inner hyphens, ending in an alphabetic top-level label.
     */
    private const string HOST_PATTERN = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/';

    /**
     * Read the collection's published entries into rules, grouped by host.
     *
     * The result carries the rules by host, sorted by host; per host, the number of
     * entry targets that could not be resolved; and the ids of entries whose host is
     * not a valid domain, which are left out.
     *
     * @param  string  $collection  The collection handle.
     * @return array{rules: array<string, list<array<string, mixed>>>, unresolved_targets: array<string, int>, invalid_hosts: list<string>}
     */
    public function read(string $collection = self::COLLECTION): array
    {
        // Read the published entries by title, so rules with the same order are tried
        // in a sequence an editor can see, and the manifest is the same on every run
        /** @var EntryQueryBuilder $query */
        $query = Entry::query();
        /** @var EntryQueryBuilder $query */
        $query = $query->where('collection', $collection);
        /** @var EntryQueryBuilder $query */
        $query = $query->whereStatus('published');
        /** @var EntryQueryBuilder $query */
        $query = $query->orderBy('title');

        // The store returns loosely typed items; the `instanceof` below narrows each one
        /** @var Collection<int, mixed> $entries */
        $entries = $query->get();

        // Resolve each entry's host against the domain list once
        $records = RedirectDomainList::records();

        $rules = [];
        $unresolved = [];
        $invalid = [];

        foreach ($entries as $entry) {
            // An entry query yields entries only, so this guard narrows the type for
            // static analysis and never skips anything
            // @pest-mutate-ignore:InstanceOfToTrue,ContinueToBreak
            if (! $entry instanceof StatamicEntry) {
                continue;
            }

            // Leave out an entry whose host names no trusted domain
            $host = RedirectDomainList::resolve($entry->get('host'), $records);

            if ($host === null) {
                $id = $entry->id();
                // A saved entry always has a string id, so the fallback is never used
                // @pest-mutate-ignore:EmptyStringToNotEmpty
                $invalid[] = is_string($id) ? $id : '';

                continue;
            }

            // Build the rule, resolving an entry target to its current URL
            $rule = self::ruleFor($entry);
            $rule = self::resolveEntryTarget($rule, $entry);

            if (($rule['target_type'] ?? null) === 'entry') {
                $unresolved[$host] = ($unresolved[$host] ?? 0) + 1;
            }

            $rules[$host][] = $rule;
        }

        ksort($rules);

        return [
            'rules' => $rules,
            'unresolved_targets' => $unresolved,
            'invalid_hosts' => $invalid,
        ];
    }

    /**
     * The hosts the application trusts: the hosts of the published domain records.
     *
     * One entry query, with no rule built and no entry target resolved, so a
     * caller that only needs to know which hosts are redirect domains does not
     * pay for a full read. A redirect rule cannot add a host to this list
     * (UAMS-Web/aether-redirects#24).
     *
     * @return list<string> The normalized hosts, sorted, once each.
     */
    public static function publishedHosts(): array
    {
        return RedirectDomainList::hosts();
    }

    /**
     * A stored host, lowercased and without a trailing dot, or null when it is not
     * a valid domain.
     *
     * @param  mixed  $value  The stored `host` value.
     */
    public static function normalizeHost(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $host = rtrim(strtolower(trim($value)), '.');

        return preg_match(self::HOST_PATTERN, $host) === 1 ? $host : null;
    }

    /**
     * The rule an entry stands for, in the global set's row shape.
     *
     * @param  StatamicEntry  $entry  The redirect domain entry.
     * @return array<string, mixed> The rule.
     */
    private static function ruleFor(StatamicEntry $entry): array
    {
        // Every use below reads the value as a condition, which casts it the same way
        // @pest-mutate-ignore:RemoveBooleanCast
        $isWholeDomain = (bool) $entry->get('whole_domain');
        $targetType = $entry->get('target_type') === 'entry' ? 'entry' : 'url';
        $targetUrl = $entry->get('target_url');

        // A whole-domain target is fixed, so a trailing wildcard appends nothing
        if ($isWholeDomain && is_string($targetUrl)) {
            $targetUrl = rtrim($targetUrl, '*');
        }

        $rule = [
            'enabled' => $entry->get('enabled') ?? true,
            'source' => $isWholeDomain ? '/*' : $entry->get('source'),
            // `&&` yields a boolean either way, so the cast changes nothing
            // @pest-mutate-ignore:RemoveBooleanCast
            'use_regex' => ! $isWholeDomain && (bool) $entry->get('use_regex'),
            'target_type' => $targetType,
            'target_entry' => $entry->get('target_entry'),
            'target_url' => $targetUrl,
            'target_query_string' => $entry->get('target_query_string'),
            'response' => $entry->get('response') ?? '301',
            'order' => $isWholeDomain ? self::WHOLE_DOMAIN_ORDER : ($entry->get('order') ?? 30),
        ];

        // Carry a validity window and an approval status when the entry has them, so
        // the engine withholds the rule exactly as it does a global-set row (UAMS-Web/uams-statamic-archive#2813)
        foreach (self::GATE_FIELDS as $field) {
            $value = $entry->get($field);

            if ($value !== null) {
                $rule[$field] = $value;
            }
        }

        return $rule;
    }

    /**
     * Turn an entry target into a url target carrying the entry's absolute URL, or
     * leave it untouched when the entry cannot be resolved to one.
     *
     * @param  array<string, mixed>  $rule  The rule.
     * @param  StatamicEntry  $entry  The redirect domain entry, for its site.
     * @return array<string, mixed> The rule.
     */
    private static function resolveEntryTarget(array $rule, StatamicEntry $entry): array
    {
        if ($rule['target_type'] !== 'entry') {
            return $rule;
        }

        // The field stores a list of one id, or a bare id
        $stored = $rule['target_entry'];
        $id = is_array($stored) ? ($stored[0] ?? null) : $stored;

        // Only a published entry with a URL is a destination; a missing or empty id
        // finds nothing
        $target = Entry::find($id);

        if (! $target instanceof StatamicEntry || ! $target->published()) {
            return $rule;
        }

        // Prefer the destination's localization on the redirect's own site
        $localized = $target->in($entry->locale());
        $url = ($localized instanceof StatamicEntry ? $localized : $target)->absoluteUrl();

        // An entry's absolute URL is null or a full URL, never empty
        // @pest-mutate-ignore:EmptyStringToNotEmpty
        if (! is_string($url) || $url === '') {
            return $rule;
        }

        return [...$rule, 'target_type' => 'url', 'target_url' => $url];
    }
}
