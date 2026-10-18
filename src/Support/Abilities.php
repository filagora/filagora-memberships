<?php

declare(strict_types=1);

namespace Filagora\Memberships\Support;

final class Abilities
{
    /** Plans, prices, and acting on a person's memberships. */
    public const MANAGE = 'filagora.memberships.manage';

    /** Reading the billing provider event log. */
    public const VIEW_BILLING = 'filagora.memberships.view_billing';
}
