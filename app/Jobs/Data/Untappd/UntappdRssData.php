<?php

namespace App\Jobs\Data\Untappd;

use App\Jobs\Base\BaseProcessingJob;
use App\Jobs\OAuth\Untappd\UntappdCheckinDetailPull;
use Carbon\Carbon;

class UntappdRssData extends BaseProcessingJob
{
    protected function getServiceName(): string
    {
        return 'untappd';
    }

    protected function getJobType(): string
    {
        return 'rss';
    }

    protected function process(): void
    {
        $items = $this->rawData['items'] ?? [];
        $events = [];

        foreach ($items as $item) {
            $guid = $item['guid'] ?? null;
            $title = $item['title'] ?? '';
            $description = $item['description'] ?? '';
            $link = $item['link'] ?? '';
            $pubDate = $item['pubDate'] ?? '';

            if (! $guid) {
                continue;
            }

            // Parse title to extract beer, brewery, and venue information
            $parsedData = $this->parseTitle($title);

            if (! $parsedData) {
                continue;
            }

            // Extract user name from title
            $userName = $this->extractUserName($title);

            // Build actor (Untappd user)
            $actor = [
                'concept' => 'user',
                'type' => 'untappd_user',
                'title' => $userName ?: 'Untappd User',
                'content' => null,
                'metadata' => [],
                'url' => null,
                'image_url' => null,
                'time' => now(),
            ];

            // Build target (beer)
            $target = [
                'concept' => 'media',
                'type' => 'untappd_beer',
                'title' => $parsedData['beerName'],
                'content' => null,
                'metadata' => [
                    'brewery' => $parsedData['breweryName'] ?? null,
                    'venue' => $parsedData['venueName'] ?? null,
                ],
                'url' => $link,
                'image_url' => null,
                'time' => $pubDate ? Carbon::parse($pubDate) : now(),
            ];

            // Build blocks
            $blocks = [];

            // Add brewery block
            if (! empty($parsedData['breweryName'])) {
                $blocks[] = [
                    'block_type' => 'beer_brewery',
                    'title' => $parsedData['breweryName'],
                    'metadata' => [],
                    'url' => null,
                    'media_url' => null,
                    'value' => null,
                    'value_multiplier' => 1,
                    'value_unit' => null,
                    'time' => $pubDate ? Carbon::parse($pubDate) : now(),
                ];
            }

            // Add comment block if description is not empty
            if (! empty(trim($description))) {
                $blocks[] = [
                    'block_type' => 'beer_comment',
                    'title' => trim($description),
                    'metadata' => [],
                    'url' => null,
                    'media_url' => null,
                    'value' => null,
                    'value_multiplier' => 1,
                    'value_unit' => null,
                    'time' => $pubDate ? Carbon::parse($pubDate) : now(),
                ];
            }

            // Build tags array
            $tags = [];
            if (! empty($parsedData['breweryName'])) {
                $tags[] = [
                    'name' => $parsedData['breweryName'],
                    'type' => 'untappd_brewery',
                ];
            }
            if (! empty($parsedData['venueName'])) {
                $tags[] = [
                    'name' => $parsedData['venueName'],
                    'type' => 'untappd_venue',
                ];
            }

            // Build source ID
            $sourceId = 'untappd_' . md5($guid);

            // Build event
            $events[] = [
                'source_id' => $sourceId,
                'time' => $pubDate ? Carbon::parse($pubDate) : now(),
                'domain' => 'health',
                'action' => 'drank',
                'value' => null,
                'value_multiplier' => 1,
                'value_unit' => null,
                'event_metadata' => [
                    'guid' => $guid,
                    'link' => $link,
                    'needs_enrichment' => true,
                    '__tags' => $tags,
                ],
                'actor' => $actor,
                'target' => $target,
                'blocks' => $blocks,
            ];
        }

        // Create events
        $created = $this->createEventsPayload($events);

        // Tag events with brewery and venue names
        foreach ($created as $event) {
            $tags = $event->event_metadata['__tags'] ?? [];
            foreach ($tags as $tagData) {
                $event->attachTag($tagData['name'], $tagData['type']);
            }
        }

        // Dispatch detail fetch jobs for newly created events
        foreach ($created as $event) {
            $checkinUrl = $event->event_metadata['link'] ?? null;
            if ($checkinUrl && ($event->event_metadata['needs_enrichment'] ?? false)) {
                UntappdCheckinDetailPull::dispatch($this->integration, $event->id, $checkinUrl)
                    ->onQueue('pull');
            }
        }
    }

    /**
     * Parse the RSS item title to extract beer, brewery, and venue information
     */
    private function parseTitle(string $title): ?array
    {
        // Pattern: "User is drinking a/an Beer Name by Brewery Name"
        // Or: "User is drinking a/an Beer Name by Brewery Name at Venue Name"
        if (preg_match('/is drinking (?:a |an )(.+?) by\s+(.+?)(?:\s+at\s+(.+))?$/', $title, $matches)) {
            return [
                'beerName' => $this->cleanBeerName(trim($matches[1])),
                'breweryName' => trim($matches[2]),
                'venueName' => isset($matches[3]) ? trim($matches[3]) : null,
            ];
        }

        return null;
    }

    /**
     * Clean beer name (handle HTML entities and special characters)
     */
    private function cleanBeerName(string $name): string
    {
        // Decode HTML entities (e.g., &apos; -> ')
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $name;
    }

    /**
     * Extract user name from title
     */
    private function extractUserName(string $title): ?string
    {
        // Try to extract name before "is drinking"
        if (preg_match('/^(.+?) is drinking/', $title, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Convert payloads to BaseProcessingJob::createEvents structure
     */
    private function createEventsPayload(array $entries)
    {
        $payloads = [];
        foreach ($entries as $entry) {
            $payloads[] = [
                'source_id' => $entry['source_id'],
                'time' => $entry['time'],
                'domain' => $entry['domain'],
                'action' => $entry['action'],
                'value' => $entry['value'],
                'value_multiplier' => $entry['value_multiplier'],
                'value_unit' => $entry['value_unit'],
                'event_metadata' => $entry['event_metadata'] ?? [],
                'actor' => $entry['actor'],
                'target' => $entry['target'],
                'blocks' => array_map(function ($b) use ($entry) {
                    return [
                        'time' => $b['time'] ?? $entry['time'],
                        'block_type' => $b['block_type'],
                        'title' => $b['title'],
                        'metadata' => $b['metadata'] ?? [],
                        'url' => $b['url'] ?? null,
                        'media_url' => $b['media_url'] ?? null,
                        'value' => $b['value'] ?? null,
                        'value_multiplier' => $b['value_multiplier'] ?? 1,
                        'value_unit' => $b['value_unit'] ?? null,
                    ];
                }, $entry['blocks'] ?? []),
            ];
        }

        return $this->createEvents($payloads);
    }
}
