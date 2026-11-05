<?php

namespace App\Jobs\Data\ManualLog;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;

class BoardGameGeekEnrichmentData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'manual_log';
    }

    protected function getJobType(): string
    {
        return 'board_game_enrichment';
    }

    protected function process(): void
    {
        $eventId = $this->rawData['event_id'] ?? null;
        $game = $this->rawData['game'] ?? null;

        if (! $eventId || ! $game) {
            return;
        }

        $event = Event::with('target')->find($eventId);

        if (! $event || ! $event->target) {
            return;
        }

        $event->target->update([
            'content' => $game['description'],
            'media_url' => $game['image'],
            'metadata' => array_merge($event->target->metadata ?? [], array_filter([
                'bgg_id' => $game['bgg_id'] ?? null,
                'year_published' => $game['year_published'] ?? null,
                'min_players' => $game['min_players'] ?? null,
                'max_players' => $game['max_players'] ?? null,
                'playing_time' => $game['playing_time'] ?? null,
                'bgg_rating' => $game['rating'] ?? null,
            ], fn ($value) => $value !== null)),
        ]);

        $players = ($game['min_players'] ?? null) && ($game['max_players'] ?? null)
            ? "{$game['min_players']}-{$game['max_players']}"
            : null;

        $event->createBlock([
            'block_type' => 'board_game_details',
            'title' => $game['name'] ?? $event->target->title,
            'metadata' => array_filter([
                'players' => $players,
                'playing_time' => isset($game['playing_time']) ? "{$game['playing_time']} min" : null,
                'year_published' => $game['year_published'] ?? null,
            ], fn ($value) => $value !== null),
            'value' => isset($game['rating']) ? (int) round(((float) $game['rating']) * 10) : null,
            'value_multiplier' => isset($game['rating']) ? 10 : 1,
            'value_unit' => isset($game['rating']) ? '/10' : null,
        ]);
    }
}
