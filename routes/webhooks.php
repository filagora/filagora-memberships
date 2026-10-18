<?php

declare(strict_types=1);

use Filagora\Memberships\Http\Controllers\PayPalWebhookController;
use Illuminate\Support\Facades\Route;

Route::post((string) config('filagora-memberships.routes.path', 'paypal/webhook'), PayPalWebhookController::class)
    ->name('filagora-memberships.webhooks.paypal');
