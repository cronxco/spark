<?php

namespace App\Jobs\Data\Vivino;

use App\Integrations\Vivino\VivinoActivityParser;
use App\Jobs\Base\BaseProcessingJob;

class VivinoActivityData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'vivino';
    }

    protected function getJobType(): string
    {
        return 'activity';
    }

    protected function process(): void
    {
        $html = $this->rawData['html'] ?? '';
        $entries = app(VivinoActivityParser::class)->parse($html);

        if (empty($entries)) {
            return;
        }

        $eventsData = [];

        foreach ($entries as $entry) {
            $blocks = [];

            if ($entry['winery'] || $entry['region'] || $entry['vintage']) {
                $blocks[] = [
                    'block_type' => 'wine_details',
                    'title' => $entry['title'],
                    'metadata' => array_filter([
                        'winery' => $entry['winery'],
                        'region' => $entry['region'],
                        'vintage' => $entry['vintage'],
                    ], fn ($value) => $value !== null),
                ];
            }

            // Vivino's activity feed doesn't reliably expose exact rating
            // timestamps, so entries are timestamped at fetch time; dedup
            // is by wine (url, falling back to title), not by date.
            $eventsData[] = [
                'source_id' => 'vivino_' . md5($entry['url'] ?? $entry['title']),
                'time' => now(),
                'actor' => [
                    'concept' => 'user',
                    'type' => 'vivino_user',
                    'title' => 'Vivino User',
                    'metadata' => [],
                ],
                'target' => [
                    'concept' => 'media',
                    'type' => 'vivino_wine',
                    'title' => $entry['title'],
                    'url' => $entry['url'],
                    'metadata' => array_filter([
                        'winery' => $entry['winery'],
                        'vintage' => $entry['vintage'],
                        'region' => $entry['region'],
                    ], fn ($value) => $value !== null),
                ],
                'domain' => 'health',
                'action' => 'drank_wine',
                'value' => $entry['rating'] !== null ? (int) round($entry['rating'] * 10) : null,
                'value_multiplier' => $entry['rating'] !== null ? 10 : 1,
                'value_unit' => $entry['rating'] !== null ? '/5' : null,
                'event_metadata' => array_filter([
                    'note' => $entry['note'],
                ], fn ($value) => $value !== null),
                'blocks' => $blocks,
            ];
        }

        $this->createEvents($eventsData);
    }
}
