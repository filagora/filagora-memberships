<?php

declare(strict_types=1);

namespace Filagora\Memberships\Enums;

/**
 * Where a membership stands. Every value ever stored is kept, so existing data loads.
 */
enum MembershipStatus: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case TRIALING = 'trialing';
    case CANCELING = 'canceling';
    case CANCELED = 'canceled';
    case PAST_DUE = 'past_due';
    case UNPAID = 'unpaid';
    case INCOMPLETE = 'incomplete';
    case INCOMPLETE_EXPIRED = 'incomplete_expired';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::ACTIVE => 'Active',
            self::TRIALING => 'Trialing',
            self::CANCELING => 'Canceling',
            self::CANCELED => 'Canceled',
            self::PAST_DUE => 'Past due',
            self::UNPAID => 'Unpaid',
            self::INCOMPLETE => 'Incomplete',
            self::INCOMPLETE_EXPIRED => 'Incomplete (expired)',
        };
    }

    /**
     * @return array<string, string> value => label, for selects and filters.
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
