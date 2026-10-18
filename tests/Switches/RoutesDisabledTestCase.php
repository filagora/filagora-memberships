<?php

declare(strict_types=1);

namespace Filagora\Memberships\Tests\Switches;

class RoutesDisabledTestCase extends SwitchedTestCase
{
    protected function routes(): array
    {
        return ['enabled' => false];
    }
}
