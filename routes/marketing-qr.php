<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use UAMSWeb\Aether\Redirects\Http\Controllers\CP\QrCodeController;

/*
 * The QR code routes of the marketing-redirect workflow (UAMS-Web/aether-redirects#14).
 *
 * `ServiceProvider::bootMarketingRedirectRoutes()` loads this file inside the `redirects/marketing`
 * group, so the prefix and the `marketing-redirects.` name prefix apply. The route tests call each
 * route by name.
 */
Route::get('{redirect}/qr', [QrCodeController::class, 'show'])->name('qr');
Route::post('{redirect}/qr', [QrCodeController::class, 'generate'])->name('qr.generate');
Route::get('{redirect}/qr/{format}', [QrCodeController::class, 'download'])->name('qr.download');
