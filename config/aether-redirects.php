<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Add-on configuration
|--------------------------------------------------------------------------
|
| Statamic merges this file into the application's config under the add-on's
| handle (the package name without its vendor: `aether-redirects`), so
| `config('aether-redirects.some_key')` reads it. The host publishes it
| to its own `config/` directory with:
|
|     php artisan vendor:publish --tag=aether-redirects-config
|
| Rename this file to `config/<handle>.php` when the package is renamed. Delete
| it, and the config test in `tests/Integration/ServiceProviderTest.php`, if the
| add-on has no settings.
|
| Give every setting a comment saying what it does and which values it takes,
| and a default that is safe to leave unset.
|
*/

return [

    /*
    | The back-half a requester types on a vanity URL request (UAMS-Web/aether-redirects#16).
    */
    'back_half' => [

        // Words a typed back-half may not be, compared without regard to case, such as ['admin', 'login'].
        // Empty (the default) reserves nothing. The form (letters, numbers and hyphens, at most 50
        // characters) and the `-QR` suffix rules are fixed in code.
        'reserved' => [],

    ],

    /*
    | The files of a QR code made with a vanity URL (UAMS-Web/aether-redirects#14).
    */
    'qr' => [

        // The handle of the asset container the SVG, PNG, PDF and EPS files are kept in. Null (the
        // default) makes no files, so a request can still ask for a QR redirect but nothing can be downloaded.
        'container' => null,

        // The folder inside that container, without a leading or trailing slash, such as 'qr-codes'.
        'folder' => 'qr-codes',

    ],

    /*
    | The Creative Services question on a redirect request (UAMS-Web/aether-redirects#25).
    |
    | Every request answers whether it involves Creative Services, and a "yes" needs a
    | job number. The format below is one setting for every request form.
    */
    'creative_services' => [

        'job_number' => [

            // A regular expression, with delimiters, that a job number must match, such as '/^\d{5}$/'.
            // Null (the default) accepts any job number, and the approver confirms it.
            'pattern' => null,

            // The format in words, shown beside the field and in the message for a number that does
            // not match, such as 'five digits'. Null (the default) uses a generic description.
            'description' => null,

        ],

    ],

];
