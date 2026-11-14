<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use UAMSWeb\Aether\Redirects\Http\Controllers\CP\TaggedUrlCascadeController;

/*
 * The tagged URL cascade route of the marketing-redirect workflow (UAMS-Web/aether-redirects#118).
 *
 * `ServiceProvider::bootMarketingRedirectRoutes()` loads this file inside the `redirects/marketing`
 * group, so the prefix and the `marketing-redirects.` name prefix apply. The route tests call the
 * route by name.
 */
Route::get('tagged-url/cascade', TaggedUrlCascadeController::class)->name('tagged-url.cascade');
