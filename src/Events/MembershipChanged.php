<?php

declare(strict_types=1);

namespace Filagora\Memberships\Events;

use Filagora\Memberships\Models\UserMembership;

/**
 * A membership was created, or something that decides what it grants changed (its status, plan,
 * end date, or entitlements), or it was deleted. Fired for every writer, including the host's own.
 */
final readonly class MembershipChanged
{
    public function __construct(
        public UserMembership $membership,
        public ?string $previousStatus,
        public bool $deleted = false,
    ) {}
}
