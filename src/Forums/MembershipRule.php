<?php

declare(strict_types=1);

namespace Filagora\Memberships\Forums;

use Filagora\Forums\Targeting\TargetingRule;
use Filagora\Forums\Targeting\Viewer;
use Filagora\Memberships\Models\UserMembership;
use Filagora\Memberships\Services\Memberships;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Database\Eloquent\Model;

/**
 * Announcement targeting: the viewer has (or, negated, has not) a current membership, optionally
 * on one plan. A guest never passes.
 */
final class MembershipRule implements TargetingRule
{
    public function __construct(private readonly Memberships $memberships) {}

    public function type(): string
    {
        return 'membership';
    }

    public function label(): string
    {
        return 'Has a membership';
    }

    public function schema(): array
    {
        return [
            TextInput::make('plan_id')->label('Plan ID (optional)')->numeric(),
            Toggle::make('negate')->label('Show to users who do NOT have it'),
        ];
    }

    public function matches(array $rule, Viewer $viewer): bool
    {
        $user = $viewer->user;

        if (! $user instanceof Model) {
            return false;
        }

        $planId = $rule['plan_id'] ?? null;
        $current = $this->memberships->currentFor($user);

        $has = $planId === null || $planId === ''
            ? $current->isNotEmpty()
            : $current->contains(fn (UserMembership $membership): bool => (int) $membership->membership_plan_id === (int) $planId);

        return ($rule['negate'] ?? false) ? ! $has : $has;
    }
}
