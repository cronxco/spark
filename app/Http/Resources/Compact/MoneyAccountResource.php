<?php

namespace App\Http\Resources\Compact;

use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventObject
 */
class MoneyAccountResource extends JsonResource
{
    private ?Event $latestBalance;

    public function withBalance(?Event $latestBalance): static
    {
        $this->latestBalance = $latestBalance;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $meta = $this->metadata ?? [];

        return [
            'id' => $this->id,
            'title' => $this->title,
            'kind' => $this->type,
            'account_type' => $meta['account_type'] ?? null,
            'currency' => $meta['currency'] ?? 'GBP',
            'is_negative_balance' => (bool) ($meta['is_negative_balance'] ?? false),
            'provider' => $meta['provider'] ?? null,
            'account_number' => $meta['account_number'] ?? null,
            'sort_code' => $meta['sort_code'] ?? null,
            'interest_rate' => isset($meta['interest_rate']) ? (float) $meta['interest_rate'] : null,
            'start_date' => $meta['start_date'] ?? null,
            'integration_id' => $meta['integration_id'] ?? null,
            'latest_balance' => $this->latestBalance
                ? $this->formatBalance($this->latestBalance, $meta['currency'] ?? 'GBP')
                : null,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatBalance(Event $event, string $currency): array
    {
        return [
            'id' => $event->id,
            'balance' => $this->resolveBalance($event),
            'currency' => $event->value_unit ?? $currency,
            'time' => $event->time?->toIso8601String(),
            'notes' => $event->event_metadata['notes'] ?? null,
        ];
    }

    private function resolveBalance(Event $event): float
    {
        if (isset($event->event_metadata['balance'])) {
            return (float) $event->event_metadata['balance'];
        }

        if ($event->value !== null && $event->value_multiplier && $event->value_multiplier > 1) {
            return $event->value / $event->value_multiplier;
        }

        return (float) ($event->value ?? 0);
    }
}
