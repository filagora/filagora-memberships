<?php

declare(strict_types=1);

namespace Filagora\Memberships\Entitlements;

use Illuminate\Support\Str;

/**
 * Which entitlement keys a plan may grant. A person takes a plan themselves, so a plan never grants
 * a wildcard or a key that `reserved_entitlements` in the config keeps back.
 */
final class PlanEntitlements
{
    public static function allows(string $key): bool
    {
        $key = trim($key);

        return ! str_contains($key, '*')
            && ! Str::is((array) config('filagora-memberships.reserved_entitlements', []), $key);
    }

    /**
     * The keys of a list that a plan may not grant.
     *
     * @param  array<array-key, mixed>  $keys
     * @return list<string>
     */
    public static function refused(array $keys): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $key): string => trim((string) $key), $keys),
            static fn (string $key): bool => $key !== '' && ! self::allows($key),
        ));
    }
}
