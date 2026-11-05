<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Tests\Support;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Statamic\Facades\Blink;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Stache;

/**
 * Roots every Stache store, the blueprint directory, the fieldset directory and
 * the local search indexes in this process's own throwaway directory for one
 * test, and puts them back after.
 *
 * Statamic's `PreventsSavingStacheItemsToDisk` reroots every store to one
 * `tests/__fixtures__/dev-null` directory that every worker shares and each
 * worker deletes in its tear-down, so two files using it under `--parallel` can
 * delete each other's items mid-test. This roots them under
 * `storage/framework/testing/isolated-stache/<worker>-<pid>`, which no other
 * process writes. Tests reach it through the `IsolatesStache` trait.
 */
final class StacheIsolation
{
    /**
     * @param  string  $root  The throwaway directory, removed by `stop()`.
     * @param  string  $originalBlueprintDirectory  The blueprint directory to restore.
     * @param  string  $originalFieldsetDirectory  The fieldset directory to restore.
     */
    private function __construct(
        private readonly string $root,
        private readonly string $originalBlueprintDirectory,
        private readonly string $originalFieldsetDirectory,
    ) {}

    /**
     * Empty this process's throwaway directory and point the stores, blueprints
     * and fieldsets at it.
     *
     * @return self The isolation to stop when the test ends.
     */
    public static function start(): self
    {
        // Name this process's directory by worker token (TEST_TOKEN) and process id
        $root = storage_path('framework/testing/isolated-stache/'.self::segment(getenv('TEST_TOKEN')));
        File::deleteDirectory($root);

        // Create it now, so the stores only ever create directories inside it
        self::ensureDirectory($root);

        $isolation = new self($root, Blueprint::directory(), Fieldset::directory());

        // Point every store at its own directory under the throwaway root
        Stache::stores()->each(function ($store) use ($root): void {
            $store->directory($root.'/stache/'.$store->key());
        });

        // Point the local search indexes at the throwaway root. Saving an entry
        // updates them, and their default directory is shared by every worker, so
        // two workers creating it at once fail with `mkdir(): File exists`
        config()->set('statamic.search.drivers.local.path', $root.'/search');

        // Point blueprints and fieldsets at the throwaway root
        Blueprint::setDirectory($root.'/blueprints');
        Fieldset::setDirectory($root.'/fieldsets');

        // Drop anything cached from before the stores moved
        Stache::clear();
        Blink::flush();

        return $isolation;
    }

    /**
     * Restore the blueprint and fieldset directories and remove the throwaway
     * directory.
     */
    public function stop(): void
    {
        Blueprint::setDirectory($this->originalBlueprintDirectory);
        Fieldset::setDirectory($this->originalFieldsetDirectory);
        File::deleteDirectory($this->root);
        Stache::clear();
    }

    /**
     * Create a directory whose parents other workers may be creating at the same
     * time. A recursive `mkdir()` warns `File exists` when another process creates
     * one of the parents between its check and its own create, and the test
     * harness turns that warning into a failure, so this ignores the warning and
     * retries until the directory exists.
     *
     * @param  string  $path  The directory to create.
     *
     * @throws RuntimeException When the directory still does not exist.
     */
    public static function ensureDirectory(string $path): void
    {
        // Each attempt creates whatever a concurrent worker has not already created
        for ($attempt = 0; $attempt < 3 && ! is_dir($path); $attempt++) {
            @mkdir($path, 0755, true);
            clearstatcache(true, $path);
        }

        if (! is_dir($path)) {
            throw new RuntimeException("Could not create [{$path}].");
        }
    }

    /**
     * The per-process directory name: the parallel worker token, or `seq` outside
     * a parallel run, then the process id.
     *
     * @param  string|false  $token  The `TEST_TOKEN` environment value, false when unset.
     * @return string The segment, unique to this process.
     */
    public static function segment(string|false $token): string
    {
        return (\is_string($token) && $token !== '' ? $token : 'seq').'-'.getmypid();
    }
}
