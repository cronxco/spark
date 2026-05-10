<?php

namespace App\Http\Resources\Compact;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Event
 */
class BalanceEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'balance' => $this->resolveBalance(),
            'currency' => $this->value_unit ?? 'GBP',
            'time' => $this->time?->toIso8601String(),
            'notes' => $this->event_metadata['notes'] ?? null,
        ];
    }

    private function resolveBalance(): float
    {
        // Manual accounts store the float in event_metadata.
        // Monzo/GoCardless store it as an integer in `value` scaled by `value_multiplier`.
        if (isset($this->event_metadata['balance'])) {
            return (float) $this->event_metadata['balance'];
        }

        if ($this->value !== null && $this->value_multiplier && $this->value_multiplier > 1) {
            return $this->value / $this->value_multiplier;
        }

        return (float) ($this->value ?? 0);
    }
}
