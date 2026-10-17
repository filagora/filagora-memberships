<?php

declare(strict_types=1);

namespace Filagora\Memberships\Billing;

use Filagora\Memberships\Contracts\BillingProvider;

/**
 * The billing providers a plan can name.
 */
final class ProviderRegistry
{
    /** @var array<string, BillingProvider> */
    private array $providers = [];

    public function register(BillingProvider $provider): void
    {
        $this->providers[strtolower($provider->key())] = $provider;
    }

    public function has(?string $key): bool
    {
        return $key !== null && isset($this->providers[strtolower($key)]);
    }

    public function get(?string $key): ?BillingProvider
    {
        return $key === null ? null : ($this->providers[strtolower($key)] ?? null);
    }

    /**
     * @return array<string, string> key => label, for the plan form.
     */
    public function options(): array
    {
        return array_map(fn (BillingProvider $provider): string => $provider->label(), $this->providers);
    }
}
