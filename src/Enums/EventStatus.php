<?php

declare(strict_types=1);

namespace Filagora\Memberships\Enums;

/**
 * Where a billing-provider event stands in the package's processing.
 */
enum EventStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case PROCESSED = 'processed';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::PROCESSING => 'Processing',
            self::PROCESSED => 'Processed',
            self::FAILED => 'Failed',
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
