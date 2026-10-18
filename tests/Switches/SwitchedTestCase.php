<?php

declare(strict_types=1);

namespace Filagora\Memberships\Tests\Switches;

use Filagora\Memberships\Tests\TestCase;

/**
 * The webhook route is registered when the application boots, so its settings have to be set
 * before that. Subclasses name them.
 */
abstract class SwitchedTestCase extends TestCase
{
    /**
     * @return array<string, mixed> config under `filagora-memberships.routes`
     */
    abstract protected function routes(): array;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        foreach ($this->routes() as $key => $value) {
            $app['config']->set("filagora-memberships.routes.{$key}", $value);
        }
    }
}
