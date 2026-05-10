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
            'balance' => (float) ($this->event_metadata['balance'] ?? 0),
            'currency' => $this->value_unit ?? 'GBP',
            'time' => $this->time?->toIso8601String(),
            'notes' => $this->event_metadata['notes'] ?? null,
        ];
    }
}
