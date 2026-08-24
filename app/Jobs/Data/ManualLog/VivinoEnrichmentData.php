<?php

namespace App\Jobs\Data\ManualLog;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;

class VivinoEnrichmentData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'manual_log';
    }

    protected function getJobType(): string
    {
        return 'wine_enrichment';
    }

    protected function process(): void
    {
        $eventId = $this->rawData['event_id'] ?? null;
        $wine = $this->rawData['wine'] ?? null;

        if (! $eventId || ! $wine) {
            return;
        }

        $event = Event::with('target')->find($eventId);

        if (! $event || ! $event->target) {
            return;
        }

        $event->target->update(array_filter([
            'media_url' => $wine['image'] ?? null,
        ], fn ($value) => $value !== null) + [
            'metadata' => array_merge($event->target->metadata ?? [], array_filter([
                'winery' => $wine['winery'] ?? null,
                'vintage' => $wine['vintage'] ?? null,
                'region' => $wine['region'] ?? null,
                'vivino_url' => $wine['url'] ?? null,
                'vivino_rating' => $wine['rating'] ?? null,
            ], fn ($value) => $value !== null)),
        ]);

        $event->createBlock([
            'block_type' => 'wine_details',
            'title' => $wine['title'] ?? $event->target->title,
            'metadata' => array_filter([
                'winery' => $wine['winery'] ?? null,
                'vintage' => $wine['vintage'] ?? null,
                'region' => $wine['region'] ?? null,
            ], fn ($value) => $value !== null),
            'value' => isset($wine['rating']) ? (int) round($wine['rating'] * 10) : null,
            'value_multiplier' => isset($wine['rating']) ? 10 : 1,
            'value_unit' => isset($wine['rating']) ? '/5' : null,
        ]);
    }
}
