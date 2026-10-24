<?php

namespace App\Jobs\Data\ManualLog;

use App\Integrations\Untappd\AlcoholUnits;
use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;

class UntappdBeerEnrichmentData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'manual_log';
    }

    protected function getJobType(): string
    {
        return 'beer_enrichment';
    }

    protected function process(): void
    {
        $eventId = $this->rawData['event_id'] ?? null;
        $beer = $this->rawData['beer'] ?? null;

        if (! $eventId || ! $beer) {
            return;
        }

        $event = Event::with('target')->find($eventId);

        if (! $event || ! $event->target) {
            return;
        }

        $details = array_filter([
            'untappd_beer_id' => $beer['beer_id'] ?? null,
            'brewery' => $beer['brewery'] ?? null,
            'style' => $beer['style'] ?? null,
            'abv' => $beer['abv'] ?? null,
            'ibu' => $beer['ibu'] ?? null,
            'aggregate_rating' => $beer['rating'] ?? null,
            'beer_url' => $beer['url'] ?? null,
        ], fn ($value) => $value !== null);

        $event->target->update(array_filter([
            'media_url' => $beer['image'] ?? null,
        ], fn ($value) => $value !== null) + [
            'metadata' => array_merge($event->target->metadata ?? [], $details),
        ]);

        AlcoholUnits::applyTo($event->fresh('target'));

        $event->createBlock([
            'block_type' => 'beer_details',
            'title' => $beer['title'] ?? $event->target->title,
            'metadata' => array_filter([
                'brewery' => $beer['brewery'] ?? null,
                'style' => $beer['style'] ?? null,
                'abv' => $beer['abv'] ?? null,
                'ibu' => $beer['ibu'] ?? null,
            ], fn ($value) => $value !== null),
            'value' => isset($beer['rating']) ? (int) round($beer['rating'] * 100) : null,
            'value_multiplier' => isset($beer['rating']) ? 100 : 1,
            'value_unit' => isset($beer['rating']) ? '/5' : null,
        ]);
    }
}
