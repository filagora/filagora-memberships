<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('mounts the webhook route under the configured prefix and path, with the configured middleware', function () {
    $route = Route::getRoutes()->getByName('filagora-memberships.webhooks.paypal');

    expect($route->uri())->toBe('billing/hooks/paypal')
        ->and($route->gatherMiddleware())->toBe([]);

    $this->postJson('/paypal/webhook', [])->assertNotFound();
    $this->call('POST', '/billing/hooks/paypal', content: 'not json')->assertStatus(400);
});
