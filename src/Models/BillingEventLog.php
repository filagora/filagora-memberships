<?php

declare(strict_types=1);

namespace Filagora\Memberships\Models;

use Filagora\Core\Support\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $provider
 * @property string $event_id
 * @property string $event_type
 * @property string $processing_status
 * @property Carbon|null $processed_at
 * @property string|null $error_message
 * @property array<string, mixed> $payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BillingEventLog extends Model
{
    protected $fillable = [
        'provider',
        'event_id',
        'event_type',
        'processing_status',
        'processed_at',
        'error_message',
        'payload',
    ];

    public function getTable(): string
    {
        return Tables::name('billing_event_logs');
    }

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function paypalResourceId(): ?string
    {
        return $this->payloadString('resource.id');
    }

    public function paypalCustomId(): ?string
    {
        return $this->payloadString('resource.custom_id');
    }

    private function payloadString(string $path): ?string
    {
        $value = data_get($this->payload, $path);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
