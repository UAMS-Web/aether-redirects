<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Normalizes Statamic Antlers tag `params` for array-safe reads.
 *
 * `Statamic\Tags\Tags` documents `params` as an array or a collection-like bag.
 * Calling `Collection::get()` is not safe when static analysis or runtime may
 * treat `params` as an array; casting a `Collection` to `array` can corrupt
 * keys, so use `all()` when the value is a `Collection`.
 */
final class TagParam
{
    /**
     * Return tag parameters as a plain associative array.
     *
     * @param  array<string, mixed>|Collection<string, mixed>  $params  Raw tag parameters from Antlers
     * @return array<string, mixed>
     */
    public static function all(
        array|Collection $params
    ): array {
        return $params instanceof Collection
            ? $params->all()
            : $params;
    }

    /**
     * Read a single tag parameter by key.
     *
     * Uses `Collection::get` for bag objects (including Statamic `Parameters`,
     * which extends `ArrayAccessor` / `Collection`) so callers do not need to
     * stub `all()` on partial mocks. Plain arrays still use `Arr::get` for
     * dotted keys.
     *
     * @param  array<string, mixed>|Collection<string, mixed>  $params  Raw tag parameters from Antlers
     * @param  string  $key  Parameter name
     * @param  mixed  $default  Value when the key is absent
     */
    public static function get(
        array|Collection $params,
        string $key,
        mixed $default = null
    ): mixed {
        if (\is_array($params)) {
            return Arr::get(
                $params,
                $key,
                $default
            );
        }

        return $params->get(
            $key,
            $default
        );
    }
}
