<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Public webhook endpoint — no session auth, protected by X-Webhook-Token header.
 * CSRF excluded via VerifyCsrfToken::$except (api/webhook/*).
 */
Route::post('/webhook/lead', [WebhookController::class, 'lead'])
    ->middleware('throttle:webhook')
    ->name('webhook.lead');
