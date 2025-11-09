<?php

namespace App\Jobs\Data\Fetch;

use App\Jobs\Concerns\EnhancedIdempotency;
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

class ProcessFetchedContent implements ShouldQueue
{
    use Dispatchable, EnhancedIdempotency, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $maxExceptions = 1;

    public function __construct(
        public Integration $integration,
        public EventObject $webpage,
        public array $extracted,
        public string $contentHash,
        public bool $forceRefresh = false
    ) {}

    public function uniqueId(): string
    {
        // Use content hash to ensure we don't process the same content multiple times
        // This prevents race conditions when multiple fetches return the same content
        return 'process_fetch_' . $this->integration->id . '_' . $this->webpage->id . '_' . $this->contentHash;
    }

    public function handle(): void
    {
        Log::info('Fetch: Processing fetched content', [
            'integration_id' => $this->integration->id,
            'webpage_id' => $this->webpage->id,
            'url' => $this->webpage->url,
        ]);

        $metadata = $this->webpage->metadata ?? [];
        $previousHash = $metadata['content_hash'] ?? null;

        // Check if content has changed (skip if force refresh is enabled)
        if (! $this->forceRefresh && $previousHash && $previousHash === $this->contentHash) {
            // Content unchanged - just update last_checked_at
            Log::info('Fetch: Content unchanged, skipping processing', [
                'url' => $this->webpage->url,
                'content_hash' => substr($this->contentHash, 0, 8),
            ]);

            $metadata['last_checked_at'] = now()->toIso8601String();
            $metadata['fetch_count'] = ($metadata['fetch_count'] ?? 0) + 1;
            $this->webpage->update(['metadata' => $metadata]);

            return;
        }

        // Content is new or changed - process it
        Log::info('Fetch: Content changed, creating event and dispatching extraction', [
            'url' => $this->webpage->url,
            'previous_hash' => $previousHash ? substr($previousHash, 0, 8) : 'none',
            'new_hash' => substr($this->contentHash, 0, 8),
            'force_refresh' => $this->forceRefresh,
        ]);

        try {
            // Check if this is a discovered linkable URL (should update source object/event)
            $isLinkable = $metadata['is_linkable'] ?? false;
            $sourceObjectId = $isLinkable ? ($metadata['discovered_from_object_id'] ?? null) : null;
            $sourceEventId = $isLinkable ? ($metadata['discovered_from_event_id'] ?? null) : null;
            $sourceIsObject = $metadata['source_is_object'] ?? false;

            // Only create daily fetch event for non-linkable URLs (manual subscriptions)
            $event = null;
            if (! $isLinkable) {
                // Create or find actor (fetch_user)
                $actorObject = EventObject::firstOrCreate(
                    [
                        'user_id' => $this->integration->user_id,
                        'concept' => 'user',
                        'type' => 'fetch_user',
                        'title' => 'Fetch',
                    ],
                    [
                        'time' => now(),
                        'metadata' => ['service' => 'fetch'],
                    ]
                );

                // Create or update today's Event
                $sourceId = 'fetch_' . $this->webpage->id . '_' . now()->format('Y-m-d');
                $action = 'fetched';

                $event = Event::updateOrCreate(
                    [
                        'source_id' => $sourceId,
                        'integration_id' => $this->integration->id,
                    ],
                    [
                        'user_id' => $this->integration->user_id,
                        'service' => 'fetch',
                        'domain' => 'knowledge',
                        'action' => $action,
                        'time' => now(),
                        'actor_id' => $actorObject->id,
                        'target_id' => $this->webpage->id,
                        'event_metadata' => [
                            'url' => $this->webpage->url,
                            'fetch_time' => now()->toIso8601String(),
                            'content_hash' => $this->contentHash,
                            'content_changed' => true,
                            'previous_hash' => $previousHash,
                        ],
                    ]
                );

                Log::info('Fetch: Event created/updated', [
                    'event_id' => $event->id,
                    'action' => $action,
                ]);

                // Create Block 1: Raw Content
                $event->createBlock([
                    'title' => 'Raw Content',
                    'block_type' => 'fetch_content',
                    'time' => $event->time,
                    'metadata' => [
                        'html' => $this->extracted['content'],
                        'text' => $this->extracted['text_content'],
                        'excerpt' => $this->extracted['excerpt'],
                    ],
                ]);

                Log::info('Fetch: Created raw content block', [
                    'event_id' => $event->id,
                ]);
            } else {
                Log::info('Fetch: Skipping daily event creation for linkable discovered URL', [
                    'url' => $this->webpage->url,
                    'source_object_id' => $sourceObjectId,
                    'source_event_id' => $sourceEventId,
                ]);
            }

            // Check if this is a one-time fetch that should be disabled after successful fetch
            $fetchMode = $metadata['fetch_mode'] ?? 'recurring';
            $newFetchCount = ($metadata['fetch_count'] ?? 0) + 1;
            $shouldDisable = ($fetchMode === 'once' && $newFetchCount >= 1);

            // Update webpage EventObject
            $this->webpage->update([
                'title' => $this->extracted['title'],
                'content' => $this->extracted['excerpt'],
                'media_url' => $this->extracted['image'],
                'metadata' => array_merge($metadata, [
                    'last_checked_at' => now()->toIso8601String(),
                    'last_changed_at' => now()->toIso8601String(),
                    'content_hash' => $this->contentHash,
                    'previous_hash' => $previousHash,
                    'fetch_count' => $newFetchCount,
                    'last_error' => null, // Clear any previous errors
                    'enabled' => $shouldDisable ? false : ($metadata['enabled'] ?? true), // Disable one-time fetches after first successful fetch
                ]),
            ]);

            if ($shouldDisable) {
                Log::info('Fetch: One-time bookmark fetched successfully and disabled', [
                    'url' => $this->webpage->url,
                    'fetch_mode' => $fetchMode,
                    'fetch_count' => $newFetchCount,
                ]);
            }

            // Check if this is a one-time fetch that's already completed
            $discoveryStatus = $metadata['discovery_status'] ?? 'pending';

            if ($fetchMode === 'once' && $discoveryStatus === 'completed') {
                Log::info('Fetch: Skipping AI processing - one-time bookmark already completed', [
                    'webpage_id' => $this->webpage->id,
                    'url' => $this->webpage->url,
                ]);

                return;
            }

            // Dispatch content extraction job
            ExtractContentJob::dispatch(
                $this->integration,
                $event,
                $this->webpage,
                $this->extracted,
                $sourceObjectId,
                $sourceEventId,
                $sourceIsObject
            );

            Log::info('Fetch: Dispatched content extraction job', [
                'event_id' => $event?->id,
                'url' => $this->webpage->url,
                'is_linkable' => $isLinkable,
                'source_object_id' => $sourceObjectId,
                'source_event_id' => $sourceEventId,
            ]);
        } catch (Exception $e) {
            Log::error('Fetch: Processing failed', [
                'url' => $this->webpage->url,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
