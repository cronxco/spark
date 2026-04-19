<?php

namespace App\Http\Resources\Compact;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Widget payload contract. Must stay ≤4 KB — WidgetKit imposes a hard
 * ceiling on the encoded TimelineEntry and we want headroom. The shape is
 * an associative array, not an Eloquent model; $resource is expected to
 * already contain the summarised fields produced by DaySummaryService.
 */
class WidgetTodayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $source = is_array($this->resource) ? $this->resource : [];

        return [
            'date' => $source['date'] ?? null,
            'headline' => $source['headline'] ?? null,
            'metrics' => $source['metrics'] ?? [],
            'next_event' => $source['next_event'] ?? null,
            'generated_at' => $source['generated_at'] ?? now()->toIso8601String(),
        ];
    }
}
