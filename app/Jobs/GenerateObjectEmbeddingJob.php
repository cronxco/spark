<?php

namespace App\Jobs;

use App\Models\EventObject;
use App\Services\EmbeddingService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateObjectEmbeddingJob implements ShouldQueue
{
    use Queueable;

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
        public EventObject $object
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EmbeddingService $embeddingService): void
    {
        try {
            // Get searchable text from object
            $searchableText = $this->object->getSearchableText();

            if (empty(trim($searchableText))) {
                Log::warning('Object has no searchable text, skipping embedding generation', [
                    'object_id' => $this->object->id,
                ]);

                return;
            }

            // Generate embedding
            $embedding = $embeddingService->embed($searchableText);

            // Store embedding in database
            $this->object->update([
                'embeddings' => EmbeddingService::formatForPostgres($embedding),
            ]);

            Log::info('Generated embedding for object', [
                'object_id' => $this->object->id,
                'text_length' => strlen($searchableText),
            ]);
        } catch (Exception $e) {
            Log::error('Failed to generate embedding for object', [
                'object_id' => $this->object->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Exception $exception): void
    {
        Log::error('GenerateObjectEmbeddingJob failed after all retries', [
            'object_id' => $this->object->id,
            'error' => $exception?->getMessage(),
        ]);
    }
}
