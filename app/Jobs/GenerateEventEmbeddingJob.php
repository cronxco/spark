<?php

namespace App\Jobs;

use App\Models\Event;
use App\Services\EmbeddingService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateEventEmbeddingJob implements ShouldQueue
{
    use Queueable;

    /**
     * The name of the queue the job should be sent to.
     *
     * @var string
     */
    public $queue = 'embeddings';

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = 60;

    /**
     * Delete the job if its models no longer exist.
     *
     * @var bool
     */
    public $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Event $event
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EmbeddingService $embeddingService): void
    {
        try {
            // Get searchable text from event
            $searchableText = $this->event->getSearchableText();

            if (empty(trim($searchableText))) {
                Log::warning('Event has no searchable text, skipping embedding generation', [
                    'event_id' => $this->event->id,
                ]);

                return;
            }

            // Generate embedding
            $embedding = $embeddingService->embed($searchableText);

            // Get embedding metadata
            $embeddingMetadata = $embeddingService->getEmbeddingMetadata();

            // Merge embedding metadata into event metadata
            $metadata = $this->event->metadata ?? [];
            $metadata = array_merge($metadata, $embeddingMetadata);

            // Store embedding and metadata in database
            $this->event->update([
                'embeddings' => EmbeddingService::formatForPostgres($embedding),
                'metadata' => $metadata,
            ]);

            Log::info('Generated embedding for event', [
                'event_id' => $this->event->id,
                'text_length' => strlen($searchableText),
                'model' => $embeddingMetadata['embedding_model'],
            ]);
        } catch (Exception $e) {
            Log::error('Failed to generate embedding for event', [
                'event_id' => $this->event->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('GenerateEventEmbeddingJob failed after all retries', [
            'event_id' => $this->event->id,
            'error' => $exception?->getMessage(),
        ]);
    }
}
