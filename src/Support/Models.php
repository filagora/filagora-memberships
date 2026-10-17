<?php

declare(strict_types=1);

namespace Filagora\Memberships\Support;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Resolves the package's model classes. The package never names a model class directly:
 * a host swaps one under `filagora.models.<key>` (or `filagora-memberships.models.<key>`) and
 * every relation and query follows.
 */
final class Models
{
    /**
     * @return class-string<Model>
     */
    public static function class(string $key): string
    {
        /** @var class-string<Model>|null $class */
        $class = config("filagora.models.{$key}") ?? config("filagora-memberships.models.{$key}");

        return $class ?? throw new InvalidArgumentException("No Filagora memberships model is registered for [{$key}].");
    }
}
