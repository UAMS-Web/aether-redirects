<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Statamic\Contracts\Globals\GlobalSet as GlobalSetContract;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Sites\Site as SiteModel;
use UAMSWeb\Aether\Redirects\Services\EdgeRedirectManifestBuilder;
use UAMSWeb\Aether\Redirects\Services\RedirectDomainRules;

/**
 * Writes the edge redirect manifest for one site (UAMS-Web/uams-statamic-archive#1935): the static-safe subset
 * of the `redirects` global set, in the provider-neutral form
 * `EdgeRedirectManifestBuilder` documents.
 *
 * With `--host` or `--hosts` it reads the `redirect_domains` collection instead
 * (UAMS-Web/uams-statamic-archive#2678), the redirects for domains that are not Statamic sites, and writes one
 * manifest per host. A host's manifest carries that host's rules alone.
 *
 * This is the step a deploy runs. The output depends on the stored content and
 * nothing else, with no timestamp, so two runs against the same store produce
 * byte-identical files. Rendering it into a particular edge provider's format is
 * a separate step, taken once a provider is chosen.
 */
#[Description('Write the edge redirect manifest for a site from the redirects global set (UAMS-Web/uams-statamic-archive#1935), or for redirect domains (UAMS-Web/uams-statamic-archive#2678)')]
#[Signature('redirects:edge-manifest
        {site? : The handle of the site to generate the manifest for}
        {--global=redirects : The handle of the redirects global set}
        {--host= : Generate the manifest for this domain from the redirect domains collection}
        {--hosts : Generate one manifest per domain in the redirect domains collection, into the --output directory}
        {--collection=redirect_domains : The handle of the redirect domains collection}
        {--output= : Write the manifest to this path instead of standard output; with --hosts, the directory to write into}')]
class GenerateEdgeRedirectManifest extends Command
{
    /**
     * Build the manifest and write it out.
     *
     * @param  EdgeRedirectManifestBuilder  $builder  Builds the manifest from the global set.
     * @param  RedirectDomainRules  $domains  Reads the redirect domains collection.
     */
    public function handle(EdgeRedirectManifestBuilder $builder, RedirectDomainRules $domains): int
    {
        $site = $this->argument('site');
        $host = $this->option('host');
        // A value-less option already reads as a boolean; the cast states the type
        // @pest-mutate-ignore:RemoveBooleanCast
        $allHosts = (bool) $this->option('hosts');
        $hasHost = is_string($host) && $host !== '';

        $siteHandle = is_string($site) ? $site : null;

        // Take exactly one input: a site, one host, or every host. PHP adds booleans
        // as integers, so the casts state the arithmetic rather than change it
        // @pest-mutate-ignore:RemoveIntegerCast
        if ((int) ($siteHandle !== null) + (int) $hasHost + (int) $allHosts !== 1) {
            $this->error('Pass a site handle, --host=<domain>, or --hosts.');

            return self::FAILURE;
        }

        // With no site, the one input is a host or every host
        if ($siteHandle === null) {
            return $this->writeHostManifests($builder, $domains, $hasHost ? $host : null);
        }

        // Refuse a site the application does not know, rather than emit an empty manifest
        if (Site::get($siteHandle) === null) {
            $this->error("Unknown site [{$siteHandle}].");

            return self::FAILURE;
        }

        // Resolve the redirects global set
        $handle = $this->stringOption('global');
        $global = GlobalSet::findByHandle($handle);

        if (! $global instanceof GlobalSetContract) {
            $this->error("Unknown global set [{$handle}].");

            return self::FAILURE;
        }

        // Build it, merging the default site's rules beneath as the serving tag does
        $default = Site::default();

        // Statamic always configures a site, so the default is always one; the check
        // narrows the facade's return type for static analysis
        // @pest-mutate-ignore:InstanceOfToTrue
        $defaultHandle = $default instanceof SiteModel ? $default->handle() : null;
        $defaultHandle = is_string($defaultHandle) ? $defaultHandle : null;

        $manifest = $builder->forSite($global, $siteHandle, $defaultHandle);
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        // Write to standard output unless a path was given
        $output = $this->option('output');

        if (! is_string($output) || $output === '') {
            $this->output->write($json);

            return self::SUCCESS;
        }

        if (file_put_contents($output, $json) === false) {
            $this->error("Could not write [{$output}].");

            return self::FAILURE;
        }

        $this->info("Wrote the edge redirect manifest for [{$siteHandle}] to [{$output}].");

        return self::SUCCESS;
    }

    /**
     * Write the manifest for one host, or one manifest per host into a directory.
     *
     * @param  EdgeRedirectManifestBuilder  $builder  Builds each host's manifest.
     * @param  RedirectDomainRules  $domains  Reads the redirect domains collection.
     * @param  string|null  $onlyHost  The one host to write, or null for every host.
     */
    private function writeHostManifests(
        EdgeRedirectManifestBuilder $builder,
        RedirectDomainRules $domains,
        ?string $onlyHost
    ): int {
        $read = $domains->read($this->stringOption('collection'));

        // Report entries left out because their host is not a valid domain
        foreach ($read['invalid_hosts'] as $id) {
            $this->warn("Skipped entry [{$id}]: its domain is not valid.");
        }

        $output = $this->option('output');
        $output = is_string($output) && $output !== '' ? $output : null;

        // One host: write it to standard output or to the given file
        if ($onlyHost !== null) {
            $host = RedirectDomainRules::normalizeHost($onlyHost);

            if ($host === null || ! array_key_exists($host, $read['rules'])) {
                $this->error("No redirect domain entries for [{$onlyHost}].");

                return self::FAILURE;
            }

            $this->reportUnresolved($host, $read['unresolved_targets']);

            return $this->emit($builder->forHost($host, $read['rules'][$host]), $output, "[{$host}]");
        }

        // Every host needs a directory to write into
        if ($output === null) {
            $this->error('--hosts writes one file per domain, so it needs --output=<directory>.');

            return self::FAILURE;
        }

        if (! is_dir($output)) {
            // A directory a concurrent run created after the check above is fine, so
            // look again before refusing. Either operator refuses the same cases but
            // that race, and the mode bits are not read back by anything here
            // @pest-mutate-ignore:BooleanAndToBooleanOr,IncrementInteger,DecrementInteger
            if (! mkdir($output, 0755, true) && ! is_dir($output)) {
                $this->error("Could not create [{$output}].");

                return self::FAILURE;
            }
        }

        // Write each host's manifest to <directory>/<host>.json
        foreach ($read['rules'] as $host => $rules) {
            $this->reportUnresolved($host, $read['unresolved_targets']);

            $status = $this->emit($builder->forHost($host, $rules), rtrim($output, '/')."/{$host}.json", "[{$host}]");

            if ($status !== self::SUCCESS) {
                return $status;
            }
        }

        $this->info('Wrote '.count($read['rules'])." redirect domain manifest(s) to [{$output}].");

        return self::SUCCESS;
    }

    /**
     * Warn about a host's entry targets that could not be resolved to a URL.
     *
     * @param  string  $host  The host.
     * @param  array<string, int>  $unresolved  The unresolved entry-target counts, by host.
     */
    private function reportUnresolved(string $host, array $unresolved): void
    {
        // A host with no count warns about nothing, and any default below one says so
        // @pest-mutate-ignore:DecrementInteger
        $count = $unresolved[$host] ?? 0;

        if ($count > 0) {
            $this->warn("[{$host}]: {$count} entry target(s) could not be resolved to a URL, so the manifest sends them to the origin.");
        }
    }

    /**
     * Write a manifest to standard output, or to a file.
     *
     * @param  array<string, mixed>  $manifest  The manifest.
     * @param  string|null  $path  The file to write, or null for standard output.
     * @param  string  $label  What the manifest is for, for the confirmation line.
     */
    private function emit(array $manifest, ?string $path, string $label): int
    {
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        if ($path === null) {
            $this->output->write($json);

            return self::SUCCESS;
        }

        if (file_put_contents($path, $json) === false) {
            $this->error("Could not write [{$path}].");

            return self::FAILURE;
        }

        $this->info("Wrote the edge redirect manifest for {$label} to [{$path}].");

        return self::SUCCESS;
    }

    /**
     * An option's value as a string. An option passed with no value reads as an
     * empty string, which no global set or collection handle matches.
     *
     * @param  string  $key  The option's name.
     * @return string The option's value, or an empty string.
     */
    private function stringOption(string $key): string
    {
        $value = $this->option($key);

        // Any fallback that names no global set or collection reads the same
        // @pest-mutate-ignore:EmptyStringToNotEmpty
        return is_string($value) ? $value : '';
    }
}
