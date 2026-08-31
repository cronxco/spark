<?php

namespace App\Jobs;

use App\Models\Event;
use App\Services\Ai\AiUsageContext;
use App\Services\Ai\EmbeddingClient;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateEventEmbeddingJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 1;

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
        public Event $event,
        public bool $bypassCache = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EmbeddingClient $embeddingService): void
    {
        if ($this->event->isInternal()) {
            return;
        }

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
            $embedding = $embeddingService->embed($searchableText, ! $this->bypassCache, AiUsageContext::forModel($this->event));

            // Get embedding metadata
            $embeddingMetadata = $embeddingService->getEmbeddingMetadata();

            // Events store their metadata in event_metadata; there is no `metadata`
            // column, so writing one is silently dropped by mass assignment and the
            // model stamp never lands. GenerateEmbeddingTask picks the same field.
            $metadata = array_merge($this->event->event_metadata ?? [], $embeddingMetadata);

            // Store embedding and metadata in database
            // Use withoutEvents() to prevent observers from triggering on this internal update
            $this->event->withoutEvents(function () use ($embedding, $metadata) {
                $this->event->update([
                    'embeddings' => EmbeddingClient::formatForPostgres($embedding),
                    'event_metadata' => $metadata,
                ]);
            });

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
