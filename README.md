# Redirects

A Statamic add-on for redirect rules: a `redirects` global set answered on the site's 404 path, rules served on redirect domains, an edge redirect manifest built from those rules, and a Control Panel workflow for requesting and approving marketing redirects.

The code is being moved into this add-on from `UAMS-Web/uams-statamic`; until that lands, this package installs and registers but serves nothing. The work is tracked in [UAMS-Web/uams-statamic#397](https://github.com/UAMS-Web/uams-statamic/issues/397).

## Requirements

PHP 8.4 or later and Statamic 6.40 or later.

## Installation

```bash
composer require uams-web/aether-redirects
```

Statamic discovers the add-on through Composer. To change its settings, publish the config file:

```bash
php artisan vendor:publish --tag=aether-redirects-config
```

## Renamed from `statamic-redirects`

This add-on was `uams-web/statamic-redirects`; that package is abandoned in favor of `uams-web/aether-redirects`. A site that required the old package changes the following:

- Run `composer remove uams-web/statamic-redirects` and `composer require uams-web/aether-redirects`.
- Code that references the PHP namespace changes `UAMSWeb\Redirects` to `UAMSWeb\Aether\Redirects`.
- The config file is `config/aether-redirects.php` and its publish tag is `aether-redirects-config`. A published `config/statamic-redirects.php` is renamed to match; the add-on handle and permissions follow the package name, so re-save any settings and role permissions stored under the old handle.
- Published assets move to `public/vendor/aether-redirects`; run `php artisan vendor:publish --tag=aether-redirects --force` and delete `public/vendor/statamic-redirects`.

Environment variables, view namespaces and Artisan commands keep their names.

## License

MIT. See [`LICENSE`](LICENSE).

## Documentation

- [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md): developing the add-on against a host.
- [`docs/SUBMISSION.md`](docs/SUBMISSION.md): the Packagist and Marketplace checklist.
- [`docs/DECISIONS.md`](docs/DECISIONS.md): the decision log.
