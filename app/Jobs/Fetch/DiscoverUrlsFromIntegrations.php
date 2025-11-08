<?php

namespace App\Jobs\Fetch;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DiscoverUrlsFromIntegrations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 600; // 10 minutes

    public function __construct(
        public Integration $integration
    ) {}

    public function handle(): void
    {
        Log::info('DiscoverUrlsFromIntegrations: Starting URL discovery', [
            'integration_id' => $this->integration->id,
            'user_id' => $this->integration->user_id,
        ]);

        // Get monitored integration IDs from config
        $monitoredIntegrationIds = $this->integration->configuration['monitor_integrations'] ?? [];

        if (empty($monitoredIntegrationIds)) {
            Log::info('DiscoverUrlsFromIntegrations: No integrations configured to monitor');

            return;
        }

        Log::info('DiscoverUrlsFromIntegrations: Monitoring integrations', [
            'monitored_ids' => $monitoredIntegrationIds,
        ]);

        $discoveredUrls = collect();

        // Extract URLs from EventObjects by finding them through Events
        // EventObjects don't have integration_id, so we need to find them via their events
        $eventObjects = EventObject::where('user_id', $this->integration->user_id)
            ->where(function ($query) use ($monitoredIntegrationIds) {
                $query->whereHas('actorEvents', function ($q) use ($monitoredIntegrationIds) {
                    $q->whereIn('integration_id', $monitoredIntegrationIds);
                })
                    ->orWhereHas('targetEvents', function ($q) use ($monitoredIntegrationIds) {
                        $q->whereIn('integration_id', $monitoredIntegrationIds);
                    });
            })
            ->get();

        Log::debug('DiscoverUrlsFromIntegrations: Found EventObjects', [
            'count' => $eventObjects->count(),
        ]);

        foreach ($eventObjects as $object) {
            // Get integration_id from the object's events (either as actor or target)
            $integrationId = $object->actorEvents()->whereIn('integration_id', $monitoredIntegrationIds)->value('integration_id')
                ?? $object->targetEvents()->whereIn('integration_id', $monitoredIntegrationIds)->value('integration_id');

            // Extract from url field
            if ($object->url) {
                $discoveredUrls->push([
                    'url' => $object->url,
                    'source_object_id' => $object->id,
                    'source_integration_id' => $integrationId,
                    'found_in' => 'url_field',
                ]);
            }

            // Extract from metadata (recursive search)
            if ($object->metadata && is_array($object->metadata)) {
                $metadataUrls = $this->extractUrlsFromArray($object->metadata);
                foreach ($metadataUrls as $url) {
                    $discoveredUrls->push([
                        'url' => $url,
                        'source_object_id' => $object->id,
                        'source_integration_id' => $integrationId,
                        'found_in' => 'metadata',
                    ]);
                }
            }

            // Extract from content field (parse HTML/text for links)
            if ($object->content) {
                $contentUrls = $this->extractUrlsFromContent($object->content);
                foreach ($contentUrls as $url) {
                    $discoveredUrls->push([
                        'url' => $url,
                        'source_object_id' => $object->id,
                        'source_integration_id' => $integrationId,
                        'found_in' => 'content',
                    ]);
                }
            }
        }

        // Extract URLs from Events
        $events = Event::whereIn('integration_id', $monitoredIntegrationIds)
            ->whereNotNull('event_metadata')
            ->get();

        Log::debug('DiscoverUrlsFromIntegrations: Found Events', [
            'count' => $events->count(),
        ]);

        foreach ($events as $event) {
            if ($event->event_metadata && is_array($event->event_metadata)) {
                $eventUrls = $this->extractUrlsFromArray($event->event_metadata);
                foreach ($eventUrls as $url) {
                    $discoveredUrls->push([
                        'url' => $url,
                        'source_event_id' => $event->id,
                        'source_integration_id' => $event->integration_id,
                        'found_in' => 'event_metadata',
                    ]);
                }
            }
        }

        Log::info('DiscoverUrlsFromIntegrations: Total URLs discovered before filtering', [
            'count' => $discoveredUrls->count(),
        ]);

        // Deduplicate by URL
        $discoveredUrls = $discoveredUrls->unique('url');

        Log::info('DiscoverUrlsFromIntegrations: URLs after deduplication', [
            'count' => $discoveredUrls->count(),
        ]);

        // Filter out URLs that are already subscribed
        // Query for fetch_webpage objects belonging to this user
        $existingUrls = EventObject::where('user_id', $this->integration->user_id)
            ->where('type', 'fetch_webpage')
            ->pluck('url')
            ->toArray();

        $newUrls = $discoveredUrls->whereNotIn('url', $existingUrls);

        Log::info('DiscoverUrlsFromIntegrations: New URLs to subscribe', [
            'count' => $newUrls->count(),
        ]);

        // Create EventObjects for new URLs
        $createdCount = 0;
        foreach ($newUrls as $urlData) {
            try {
                $domain = parse_url($urlData['url'], PHP_URL_HOST);

                if (! $domain) {
                    Log::warning('DiscoverUrlsFromIntegrations: Invalid URL, skipping', [
                        'url' => $urlData['url'],
                    ]);

                    continue;
                }

                EventObject::create([
                    'user_id' => $this->integration->user_id,
                    'concept' => 'bookmark',
                    'type' => 'fetch_webpage',
                    'title' => $urlData['url'], // Will be updated on first fetch
                    'url' => $urlData['url'],
                    'time' => now(),
                    'metadata' => [
                        'domain' => $domain,
                        'fetch_integration_id' => $this->integration->id, // Store which Fetch integration manages this
                        'subscription_source' => 'discovered',
                        'discovered_from_integration_id' => $urlData['source_integration_id'],
                        'discovered_from_object_id' => $urlData['source_object_id'] ?? null,
                        'discovered_from_event_id' => $urlData['source_event_id'] ?? null,
                        'discovered_at' => now()->toIso8601String(),
                        'found_in' => $urlData['found_in'],
                        'enabled' => true,
                        'last_checked_at' => null,
                        'last_changed_at' => null,
                        'content_hash' => null,
                        'fetch_count' => 0,
                    ],
                ]);

                $createdCount++;

                Log::debug('DiscoverUrlsFromIntegrations: Created EventObject for URL', [
                    'url' => $urlData['url'],
                    'domain' => $domain,
                ]);
            } catch (Exception $e) {
                Log::error('DiscoverUrlsFromIntegrations: Failed to create EventObject', [
                    'url' => $urlData['url'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('DiscoverUrlsFromIntegrations: Completed', [
            'discovered_total' => $discoveredUrls->count(),
            'new_urls' => $newUrls->count(),
            'created' => $createdCount,
            'monitored_integrations' => count($monitoredIntegrationIds),
        ]);
    }

    /**
     * Extract URLs from a nested array recursively.
     */
    private function extractUrlsFromArray(array $data): array
    {
        $urls = [];
        $pattern = '/https?:\/\/[^\s<>"]+/i';

        array_walk_recursive($data, function ($value) use (&$urls, $pattern) {
            if (is_string($value)) {
                preg_match_all($pattern, $value, $matches);
                $urls = array_merge($urls, $matches[0]);
            }
        });

        return array_unique($urls);
    }

    /**
     * Extract URLs from HTML/text content.
     */
    private function extractUrlsFromContent(string $content): array
    {
        $urls = [];
        $pattern = '/https?:\/\/[^\s<>"]+/i';

        // Extract from plain text URLs
        preg_match_all($pattern, $content, $matches);
        $urls = array_merge($urls, $matches[0]);

        // Extract from HTML anchor tags
        preg_match_all('/<a[^>]+href=(["\'])([^"\']+)\1/i', $content, $matches);
        if (isset($matches[2])) {
            $urls = array_merge($urls, $matches[2]);
        }

        // Filter to only http/https
        $urls = array_filter($urls, function ($url) {
            return preg_match('/^https?:\/\//i', $url);
        });

        return array_unique($urls);
    }
}
