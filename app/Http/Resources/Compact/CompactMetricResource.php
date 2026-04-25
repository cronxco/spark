<?php

namespace App\Http\Resources\Compact;

use App\Models\MetricStatistic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MetricStatistic
 */
class CompactMetricResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'identifier' => $this->getIdentifier(),
            'display_name' => $this->getDisplayName(),
            'service' => $this->service,
            'action' => $this->action,
            'unit' => $this->value_unit,
            'event_count' => $this->event_count,
            'mean' => $this->mean_value !== null ? (float) $this->mean_value : null,
            'last_event_at' => $this->last_event_at?->toIso8601String(),
        ];
    }
}
