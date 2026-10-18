<?php

declare(strict_types=1);

namespace Filagora\Memberships\Tests\Switches;

class CustomRouteTestCase extends SwitchedTestCase
{
    protected function routes(): array
    {
        return ['prefix' => 'billing', 'path' => 'hooks/paypal', 'middleware' => []];
    }
}
