Written against v0.5.2.

The add-on's settings live in `config/aether-redirects.php`. The add-on works without publishing the file; publish it to change a value:

```bash
php artisan vendor:publish --tag=aether-redirects-config
```

Statamic merges the file into the application's config under the add-on's handle, so `config('aether-redirects.qr.folder')` reads a value.

| Key | Values | Default | Effect |
| --- | --- | --- | --- |
| `back_half.reserved` | a list of words | `[]` | Words a typed back-half may not be, compared without regard to case, such as `['admin', 'login']`. Empty reserves nothing. |
| `qr.container` | an asset container handle, or `null` | `null` | The container the SVG, PNG, PDF and EPS files of a QR code are kept in. With `null` a request can still ask for a QR redirect, but no files can be made. |
| `qr.folder` | a folder name, without a leading or trailing slash | `'qr-codes'` | The folder inside that container. |
| `creative_services.job_number.pattern` | a regular expression with delimiters, or `null` | `null` | The format a Creative Services job number must match, such as `'/^\d{5}$/'`. With `null` any job number is accepted and the approver confirms it. |
| `creative_services.job_number.description` | text, or `null` | `null` | The format in words, shown beside the field and in the message for a number that does not match, such as `'five digits'`. With `null` a generic description is used. |

The form of a typed back-half (letters, numbers and hyphens, at most 50 characters) and the `-QR` rule are fixed in code. A job number is at most 100 characters.

## Example

```php
// config/aether-redirects.php
return [
    'back_half' => [
        'reserved' => ['admin', 'login'],
    ],
    'qr' => [
        'container' => 'qr_codes',
        'folder' => 'qr-codes',
    ],
    'creative_services' => [
        'job_number' => [
            'pattern' => '/^\d{5}$/',
            'description' => 'five digits',
        ],
    ],
];
```

## Container bindings

The generators for a vanity back-half and a short-link code are bound in the service container as `aether-redirects.back-half-generator` and `aether-redirects.short-code-generator`. A site or a test may rebind them; each must return a `RandomCode`.

## Reference

- [Requesting a vanity URL](Requesting-a-vanity-URL.md)
- [Setting up a site](Setting-up-a-site.md)
