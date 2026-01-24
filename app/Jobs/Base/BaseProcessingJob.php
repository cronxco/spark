<?php

namespace App\Jobs\Base;

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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Sentry\EventHint;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;
use Throwable;

abstract class BaseProcessingJob implements ShouldQueue
{
    use Dispatchable, EnhancedIdempotency, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes for processing

    public $tries = 2;

    public $backoff = [120, 300]; // Retry after 2, 5 minutes

    protected Integration $integration;

    protected array $rawData;

    protected string $serviceName;

    /**
     * Create a new job instance.
     */
    public function __construct(Integration $integration, array $rawData)
    {
        $this->integration = $integration;
        $this->rawData = $rawData;
        $this->serviceName = $this->getServiceName();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $hub = SentrySdk::getCurrentHub();
        $txContext = new TransactionContext;
        $txContext->setName('job.process:'.$this->serviceName.':'.$this->getJobType());
        $txContext->setOp('job');
        $transaction = $hub->startTransaction($txContext);
        $hub->setSpan($transaction);

        try {
            // Check for recent processing to prevent duplicates
            $idempotencyKey = $this->generateIdempotencyKey($this->rawData);
            if ($this->hasBeenProcessedRecently($idempotencyKey)) {
                Log::info("Skipping {$this->getJobType()} processing - already processed recently", [
                    'integration_id' => $this->integration->id,
                    'service' => $this->serviceName,
                ]);
                $transaction->setStatus(SpanStatus::ok());

                return;
            }

            Log::info("Starting {$this->getJobType()} processing for integration {$this->integration->id} ({$this->serviceName})");

            $span = $transaction->startChild((new SpanContext)->setOp('integration.process')->setDescription($this->serviceName));
            $this->process();
            $span->finish();

            // Mark as successfully processed
            $this->cleanupAfterSuccess();

            Log::info("Completed {$this->getJobType()} processing for integration {$this->integration->id} ({$this->serviceName})");
            $transaction->setStatus(SpanStatus::ok());
        } catch (Exception $e) {
            Log::error("Failed {$this->getJobType()} processing for integration {$this->integration->id} ({$this->serviceName})", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $transaction->setStatus(SpanStatus::internalError());
            throw $e;
        } finally {
            $transaction->finish();
            $hub->setSpan(null);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error("{$this->getJobType()} processing job failed permanently for integration {$this->integration->id} ({$this->serviceName})", [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'attempts' => $this->attempts(),
        ]);

        // Send alert for permanent failures
        SentrySdk::getCurrentHub()->captureMessage("Job failed permanently: {$this->serviceName}:{$this->getJobType()}", Severity::error(), EventHint::fromArray([
            'extra' => [
                'integration_id' => $this->integration->id,
                'service' => $this->serviceName,
                'job_type' => $this->getJobType(),
                'error' => $exception->getMessage(),
            ],
        ]));
    }

    /**
     * Get the unique job identifier for idempotency.
     */
    public function uniqueId(): string
    {
        return $this->serviceName.'_'.$this->getJobType().'_'.$this->integration->id.'_'.md5(serialize($this->rawData));
    }

    /**
     * Get the service name for this job (e.g., 'monzo', 'oura').
     */
    abstract protected function getServiceName(): string;

    /**
     * Get the job type for logging (e.g., 'accounts', 'transactions').
     */
    abstract protected function getJobType(): string;

    /**
     * Process the raw data into events, objects, and blocks.
     */
    abstract protected function process(): void;

    /**
     * Helper method to create events from processed data.
     */
    protected function createEvents(array $eventData): Collection
    {
        $events = collect();

        foreach ($eventData as $data) {
            // Skip if event already exists to prevent duplicate key violations
            if ($this->eventExists($data['source_id'])) {
                Log::info('Skipping duplicate event creation', [
                    'integration_id' => $this->integration->id,
                    'source_id' => $data['source_id'],
                    'service' => $this->serviceName,
                    'job_type' => $this->getJobType(),
                    'action' => 'duplicate_event_skipped',
                ]);

                continue;
            }

            // Create actor object
            $actor = $this->createOrUpdateObject($data['actor']);

            // Create target object
            $target = $this->createOrUpdateObject($data['target']);

            try {
                // Create event with duplicate handling
                $event = Event::create([
                    'source_id' => $data['source_id'],
                    'time' => $data['time'],
                    'integration_id' => $this->integration->id,
                    'actor_id' => $actor->id,
                    'actor_metadata' => $data['actor_metadata'] ?? [],
                    'service' => $this->serviceName,
                    'domain' => $data['domain'],
                    'action' => $data['action'],
                    'value' => $data['value'] ?? null,
                    'value_multiplier' => $data['value_multiplier'] ?? 1,
                    'value_unit' => $data['value_unit'] ?? null,
                    'event_metadata' => $data['event_metadata'] ?? [],
                    'target_id' => $target->id,
                    'target_metadata' => $data['target_metadata'] ?? [],
                    'embeddings' => $data['embeddings'] ?? null,
                ]);

                // Create blocks if any
                if (isset($data['blocks'])) {
                    foreach ($data['blocks'] as $blockData) {
                        $event->createBlock([
                            'time' => $blockData['time'] ?? $event->time,
                            'block_type' => $blockData['block_type'] ?? '',
                            'title' => $blockData['title'],
                            'metadata' => $blockData['metadata'] ?? [],
                            'url' => $blockData['url'] ?? null,
                            'media_url' => $blockData['media_url'] ?? null,
                            'value' => $blockData['value'] ?? null,
                            'value_multiplier' => $blockData['value_multiplier'] ?? 1,
                            'value_unit' => $blockData['value_unit'] ?? null,
                            'embeddings' => $blockData['embeddings'] ?? null,
                        ]);
                    }
                }

                // Attach tags if provided in payload (supports optional typed tags)
                if (isset($data['tags']) && is_array($data['tags'])) {
                    foreach ($data['tags'] as $tag) {
                        if (is_array($tag)) {
                            $name = (string) ($tag['name'] ?? '');
                            $type = $tag['type'] ?? null;
                            if ($name !== '') {
                                if ($type) {
                                    $event->attachTag($name, (string) $type);
                                } else {
                                    $event->attachTag($name, 'spark_tag');
                                }
                            }
                        } elseif (is_string($tag) && $tag !== '') {
                            $event->attachTag($tag, 'spark_tag');
                        }
                    }
                }

                // Set location and link to place if route data is present (Apple Health workouts)
                if (isset($data['event_metadata']['route_points']) && ! empty($data['event_metadata']['route_points'])) {
                    $routePoints = $data['event_metadata']['route_points'];
                    $firstPoint = $routePoints[0];

                    if (isset($firstPoint['lat'], $firstPoint['lng']) && $firstPoint['lat'] !== null && $firstPoint['lng'] !== null) {
                        // Get location tag to determine if outdoor
                        $locationTag = $data['event_metadata']['location'] ?? null;
                        $isOutdoor = $locationTag !== 'Indoor';

                        // Reverse geocode the starting location
                        $geocodingService = app(\App\Services\GeocodingService::class);
                        $geocoded = $geocodingService->reverseGeocode(
                            $firstPoint['lat'],
                            $firstPoint['lng']
                        );

                        $address = $geocoded['formatted_address'] ?? 'Workout location';

                        // Set event location
                        $event->setLocation(
                            $firstPoint['lat'],
                            $firstPoint['lng'],
                            $address,
                            'apple_health_route'
                        );

                        // Link to place only for outdoor workouts
                        if ($isOutdoor) {
                            $placeService = app(\App\Services\PlaceDetectionService::class);
                            $placeService->detectAndLinkPlaceForEvent($event);
                        }
                    }
                }

                $events->push($event);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // Handle race condition where event was created between our check and create
                Log::warning('Race condition detected - event already exists', [
                    'integration_id' => $this->integration->id,
                    'source_id' => $data['source_id'],
                    'service' => $this->serviceName,
                    'job_type' => $this->getJobType(),
                    'error' => $e->getMessage(),
                    'action' => 'race_condition_handled',
                ]);

                // Try to find the existing event
                $existingEvent = Event::where('integration_id', $this->integration->id)
                    ->where('source_id', $data['source_id'])
                    ->whereNull('deleted_at')
                    ->first();

                if ($existingEvent) {
                    $events->push($existingEvent);
                }
            }
        }

        return $events;
    }

    /**
     * Helper method to create or update objects.
     */
    protected function createOrUpdateObject(array $objectData): EventObject
    {
        return EventObject::updateOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => $objectData['concept'],
                'type' => $objectData['type'],
                'title' => $objectData['title'],
            ],
            [
                'time' => $objectData['time'] ?? now(),
                'content' => $objectData['content'] ?? null,
                'metadata' => $objectData['metadata'] ?? [],
                'url' => $objectData['url'] ?? null,
                'media_url' => $objectData['image_url'] ?? null,
                'embeddings' => $objectData['embeddings'] ?? null,
            ]
        );
    }

    /**
     * Helper method to check if an event already exists to prevent duplicates.
     */
    protected function eventExists(string $sourceId): bool
    {
        return Event::where('integration_id', $this->integration->id)
            ->where('source_id', $sourceId)
            ->whereNull('deleted_at')
            ->exists();
    }

    /**
     * Download images from media_url and metadata fields to Media Library.
     * Call this after creating events to convert external image URLs to Media Library attachments.
     */
    protected function downloadImagesToMediaLibrary(Collection $events): void
    {
        $mediaHelper = app(\App\Services\Media\MediaDownloadHelper::class);

        foreach ($events as $event) {
            // Download images from blocks with media_url
            foreach ($event->blocks as $block) {
                if ($block->media_url && ! $block->hasMedia('downloaded_images')) {
                    try {
                        $media = $mediaHelper->downloadAndAttachMedia(
                            $block->media_url,
                            $block,
                            'downloaded_images'
                        );

                        if ($media) {
                            Log::debug('Downloaded image to Media Library for block', [
                                'block_id' => $block->id,
                                'block_type' => $block->block_type,
                                'media_url' => $block->media_url,
                                'media_id' => $media->id,
                            ]);
                        }
                    } catch (Exception $e) {
                        Log::warning('Failed to download image for block', [
                            'block_id' => $block->id,
                            'media_url' => $block->media_url,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Download images from metadata['image'] or metadata['image_url']
                $metadata = $block->metadata ?? [];
                $imageUrl = $metadata['image'] ?? $metadata['image_url'] ?? null;

                if ($imageUrl && ! $block->hasMedia('downloaded_images')) {
                    try {
                        $media = $mediaHelper->downloadAndAttachMedia(
                            $imageUrl,
                            $block,
                            'downloaded_images'
                        );

                        if ($media) {
                            Log::debug('Downloaded image from metadata to Media Library for block', [
                                'block_id' => $block->id,
                                'block_type' => $block->block_type,
                                'image_url' => $imageUrl,
                                'media_id' => $media->id,
                            ]);
                        }
                    } catch (Exception $e) {
                        Log::warning('Failed to download image from metadata for block', [
                            'block_id' => $block->id,
                            'image_url' => $imageUrl,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // Download target object images (e.g., Reddit post image)
            if ($event->target && $event->target->media_url && ! $event->target->hasMedia('downloaded_images')) {
                try {
                    $media = $mediaHelper->downloadAndAttachMedia(
                        $event->target->media_url,
                        $event->target,
                        'downloaded_images'
                    );

                    if ($media) {
                        Log::debug('Downloaded image to Media Library for target object', [
                            'object_id' => $event->target->id,
                            'object_type' => $event->target->type,
                            'media_url' => $event->target->media_url,
                            'media_id' => $media->id,
                        ]);
                    }
                } catch (Exception $e) {
                    Log::warning('Failed to download image for target object', [
                        'object_id' => $event->target->id,
                        'media_url' => $event->target->media_url,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Download actor object images (e.g., avatar)
            if ($event->actor && $event->actor->media_url && ! $event->actor->hasMedia('downloaded_images')) {
                try {
                    $media = $mediaHelper->downloadAndAttachMedia(
                        $event->actor->media_url,
                        $event->actor,
                        'downloaded_images'
                    );

                    if ($media) {
                        Log::debug('Downloaded image to Media Library for actor object', [
                            'object_id' => $event->actor->id,
                            'object_type' => $event->actor->type,
                            'media_url' => $event->actor->media_url,
                            'media_id' => $media->id,
                        ]);
                    }
                } catch (Exception $e) {
                    Log::warning('Failed to download image for actor object', [
                        'object_id' => $event->actor->id,
                        'media_url' => $event->actor->media_url,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
