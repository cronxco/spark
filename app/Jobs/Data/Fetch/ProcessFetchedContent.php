<?php

namespace App\Jobs\Data\Fetch;

use App\Jobs\Concerns\EnhancedIdempotency;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Services\Media\MediaDeduplicationService;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessFetchedContent implements ShouldQueue
{
    use Dispatchable, EnhancedIdempotency, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $maxExceptions = 1;

    public string $fetchRunId;

    public function __construct(
        public Integration $integration,
        public EventObject $webpage,
        public array $extracted,
        public string $contentHash,
        public bool $forceRefresh = false,
        ?string $fetchRunId = null,
    ) {
        $this->fetchRunId = $fetchRunId ?? (string) Str::uuid();
    }

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

        try {
            $metadata = $this->webpage->metadata ?? [];
            $isLinkable = $metadata['is_linkable'] ?? false;
            $sourceObjectId = $isLinkable ? ($metadata['discovered_from_object_id'] ?? null) : null;
            $sourceEventId = $isLinkable ? ($metadata['discovered_from_event_id'] ?? null) : null;
            $sourceIsObject = $metadata['source_is_object'] ?? false;
            if ($isLinkable) {
                if (! $this->processLinkableWebpage()) {
                    return;
                }

                $event = null;
            } else {
                $event = $this->createRevision();

                if ($event === null) {
                    return;
                }
            }

            // If this is a discovered URL with a source object, also attach the article image to the source
            if ($sourceObjectId && $this->webpage->hasMedia('article_images')) {
                $this->attachArticleImageToSourceObject($sourceObjectId);
            }

            // Check if this is a one-time fetch that's already completed
            $latestMetadata = $this->webpage->fresh()->metadata ?? [];
            $fetchMode = $latestMetadata['fetch_mode'] ?? 'recurring';
            $discoveryStatus = $latestMetadata['discovery_status'] ?? 'pending';

            if ($fetchMode === 'once' && $discoveryStatus === 'completed') {
                Log::info('Fetch: Skipping AI processing - one-time bookmark already completed', [
                    'webpage_id' => $this->webpage->id,
                    'url' => $this->webpage->url,
                ]);

                return;
            }

            if ($isLinkable) {
                // Linkable discovered URLs have no Event, so they stay on the direct job path.
                ExtractContentJob::dispatch(
                    $this->integration,
                    null,
                    $this->webpage,
                    $this->extracted,
                    $sourceObjectId,
                    $sourceEventId,
                    $sourceIsObject
                );
            }

            Log::info('Fetch: AI processing handoff complete', [
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

    private function createRevision(): ?Event
    {
        $fetchedAt = CarbonImmutable::now();
        $timezone = $this->integration->configuration['schedule_timezone'] ?? 'UTC';
        $fetchDay = $fetchedAt->setTimezone($timezone)->toDateString();
        $dayStart = CarbonImmutable::parse($fetchDay, $timezone)->startOfDay()->utc();
        $dayEnd = $dayStart->addDay();

        $actorObject = EventObject::firstOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'user',
                'type' => 'fetch_user',
                'title' => 'Fetch',
            ],
            [
                'time' => $fetchedAt,
                'metadata' => ['service' => 'fetch'],
            ]
        );

        return DB::transaction(function () use ($actorObject, $dayEnd, $dayStart, $fetchDay, $fetchedAt): ?Event {
            $webpage = EventObject::query()->lockForUpdate()->findOrFail($this->webpage->id);
            $metadata = $webpage->metadata ?? [];
            $previousHash = $metadata['content_hash'] ?? null;
            $newFetchCount = ($metadata['fetch_count'] ?? 0) + 1;

            $existingRevision = Event::query()
                ->where('integration_id', $this->integration->id)
                ->where('source_id', 'fetch_' . $webpage->id . '_' . $this->fetchRunId)
                ->first();

            if ($existingRevision) {
                return $existingRevision;
            }

            if (! $this->forceRefresh && $previousHash === $this->contentHash) {
                $webpage->update([
                    'metadata' => array_merge($metadata, [
                        'last_checked_at' => $fetchedAt->toIso8601String(),
                        'fetch_count' => $newFetchCount,
                        'last_error' => null,
                    ]),
                ]);

                Log::info('Fetch: Content unchanged, skipping revision', [
                    'url' => $webpage->url,
                    'content_hash' => substr($this->contentHash, 0, 8),
                ]);

                return null;
            }

            $fetchMode = $metadata['fetch_mode'] ?? 'recurring';
            $action = $fetchMode === 'once' ? 'bookmarked' : 'fetched';
            $mediaUrl = $webpage->media_url ?: ($this->extracted['image'] ?? null);

            if ($fetchMode !== 'once') {
                Event::query()
                    ->where('integration_id', $this->integration->id)
                    ->where('target_id', $webpage->id)
                    ->where('service', 'fetch')
                    ->where('action', 'fetched')
                    ->where('time', '>=', $dayStart)
                    ->where('time', '<', $dayEnd)
                    ->update(['action' => 'updated']);
            }

            $event = Event::create([
                'source_id' => 'fetch_' . $webpage->id . '_' . $this->fetchRunId,
                'integration_id' => $this->integration->id,
                'service' => 'fetch',
                'domain' => 'knowledge',
                'action' => $action,
                'time' => $fetchedAt,
                'actor_id' => $actorObject->id,
                'target_id' => $webpage->id,
                'target_metadata' => [
                    'title' => $this->extracted['title'],
                    'url' => $webpage->url,
                    'media_url' => $mediaUrl,
                    'excerpt' => $this->extracted['excerpt'],
                    'content_hash' => $this->contentHash,
                    'fetched_at' => $fetchedAt->toIso8601String(),
                ],
                'event_metadata' => [
                    'url' => $webpage->url,
                    'fetch_time' => $fetchedAt->toIso8601String(),
                    'fetch_day' => $fetchDay,
                    'fetch_run_id' => $this->fetchRunId,
                    'revision_model_version' => 1,
                    'content_hash' => $this->contentHash,
                    'content_changed' => true,
                    'previous_hash' => $previousHash,
                    'enrichment_status' => 'pending',
                ],
            ]);

            $event->createBlock([
                'title' => 'Raw Content',
                'block_type' => 'fetch_content',
                'time' => $fetchedAt,
                'metadata' => [
                    'html' => $this->extracted['content'],
                    'text' => $this->extracted['text_content'],
                    'excerpt' => $this->extracted['excerpt'],
                    'content_hash' => $this->contentHash,
                ],
            ]);

            $shouldDisable = $fetchMode === 'once' && $newFetchCount >= 1;
            $webpage->update([
                'title' => $this->extracted['title'],
                'content' => $this->extracted['excerpt'],
                'media_url' => $mediaUrl,
                'metadata' => array_merge($metadata, [
                    'last_checked_at' => $fetchedAt->toIso8601String(),
                    'last_changed_at' => $fetchedAt->toIso8601String(),
                    'content_hash' => $this->contentHash,
                    'previous_hash' => $previousHash,
                    'fetch_count' => $newFetchCount,
                    'last_error' => null,
                    'enabled' => $shouldDisable ? false : ($metadata['enabled'] ?? true),
                    'latest_event_id' => $event->id,
                    'latest_event_at' => $fetchedAt->toIso8601String(),
                    'pipeline_status' => 'pending',
                    'revision_repair_queued_for_hash' => null,
                    'revision_repair_completed_at' => $fetchedAt->toIso8601String(),
                ]),
            ]);

            Log::info('Fetch: Immutable revision created', [
                'event_id' => $event->id,
                'action' => $action,
                'fetch_day' => $fetchDay,
                'previous_hash' => $previousHash ? substr($previousHash, 0, 8) : null,
                'new_hash' => substr($this->contentHash, 0, 8),
            ]);

            return $event;
        }, 3);
    }

    private function processLinkableWebpage(): bool
    {
        $webpage = $this->webpage->fresh();
        $metadata = $webpage->metadata ?? [];
        $previousHash = $metadata['content_hash'] ?? null;

        if (! $this->forceRefresh && $previousHash === $this->contentHash) {
            $webpage->update([
                'metadata' => array_merge($metadata, [
                    'last_checked_at' => now()->toIso8601String(),
                    'fetch_count' => ($metadata['fetch_count'] ?? 0) + 1,
                ]),
            ]);

            return false;
        }

        $webpage->update([
            'title' => $this->extracted['title'],
            'content' => $this->extracted['excerpt'],
            'media_url' => $webpage->media_url ?: ($this->extracted['image'] ?? null),
            'metadata' => array_merge($metadata, [
                'last_checked_at' => now()->toIso8601String(),
                'last_changed_at' => now()->toIso8601String(),
                'content_hash' => $this->contentHash,
                'previous_hash' => $previousHash,
                'fetch_count' => ($metadata['fetch_count'] ?? 0) + 1,
                'last_error' => null,
            ]),
        ]);

        return true;
    }

    /**
     * Attach the article image from the webpage to the source object.
     * This allows discovered URLs to share their article image with the source.
     */
    private function attachArticleImageToSourceObject(string $sourceObjectId): void
    {
        try {
            $sourceObject = EventObject::find($sourceObjectId);
            if (! $sourceObject) {
                return;
            }

            // Get the article image from the webpage
            $articleImage = $this->webpage->getFirstMedia('article_images');
            if (! $articleImage) {
                return;
            }

            // Use deduplication service to attach the same image to source object
            $deduplicationService = app(MediaDeduplicationService::class);
            $deduplicationService->attachMediaToModel(
                $articleImage,
                $sourceObject,
                'article_images'
            );

            Log::debug('Fetch: Article image attached to source object', [
                'webpage_id' => $this->webpage->id,
                'source_object_id' => $sourceObjectId,
                'media_uuid' => $articleImage->uuid,
            ]);
        } catch (Exception $e) {
            Log::warning('Fetch: Failed to attach article image to source object', [
                'webpage_id' => $this->webpage->id,
                'source_object_id' => $sourceObjectId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
