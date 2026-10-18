<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('registers no webhook route when the routes are switched off', function () {
    expect(Route::has('filagora-memberships.webhooks.paypal'))->toBeFalse();

    $this->postJson('/paypal/webhook', [])->assertNotFound();
});
