<?php

declare(strict_types=1);

namespace Filagora\Memberships\Forums;

use Filagora\Forums\Contracts\PaidAccessCheck;
use Filagora\Memberships\Services\Memberships;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * "Paid" access for the forums package: the person has a membership that is currently active.
 * Bind it as `Filagora\Forums\Contracts\PaidAccessCheck`, or wrap it to add a rule of your own.
 */
final class MembershipsPaidAccess implements PaidAccessCheck
{
    public function __construct(private readonly Memberships $memberships) {}

    public function hasPaidAccess(Authenticatable $user): bool
    {
        return $user instanceof Model && $this->memberships->hasActive($user);
    }
}
