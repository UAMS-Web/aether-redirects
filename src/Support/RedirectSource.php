<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Support;

use UAMSWeb\Aether\Redirects\Tags\FindRedirectRule;

/**
 * Normalizes redirect source paths for storage and collision comparison.
 *
 * Two normalization levels are exposed:
 *   - {@see normalize()} produces the canonical *stored* form — a single leading
 *     slash, no trailing slash (except the bare root), case preserved — which
 *     the serving tag ({@see FindRedirectRule}) exact-matches against
 *     the request URI.
 *   - {@see collisionKey()} lowercases that canonical form so trivial case
 *     variants (`/Flu` vs `/flu`) are treated as the same source during
 *     collision detection (UAMS-Web/uams-statamic-archive#1356), even though each still serves only its own
 *     exact-case path.
 */
final class RedirectSource
{
    /**
     * Canonicalize a raw source path for storage.
     *
     * Trims surrounding whitespace, forces a single leading slash, and strips a
     * trailing slash (except for the bare root). Case is preserved so the
     * serving tag's exact match stays faithful to what the editor entered.
     *
     * @param  string  $raw  The editor-entered source path
     * @return string The canonical stored source path
     */
    public static function normalize(string $raw): string
    {
        // Strip surrounding whitespace, then any leading/trailing slashes, and
        // re-add exactly one leading slash. A path that reduces to nothing (empty,
        // whitespace, or all slashes) canonicalizes to the bare site root.
        return '/'.trim(trim($raw), '/');
    }

    /**
     * Derive the case-insensitive collision key for a source path — the
     * canonical form, lowercased — so `/Flu` and `/flu` are treated as the same
     * source when detecting overlapping redirects (UAMS-Web/uams-statamic-archive#1356).
     *
     * @param  string  $raw  The source path (raw or already normalized)
     * @return string The lowercased canonical collision key
     */
    public static function collisionKey(string $raw): string
    {
        // Lowercase the canonical form; mb_strtolower handles any non-ASCII path.
        return mb_strtolower(self::normalize($raw));
    }
}
