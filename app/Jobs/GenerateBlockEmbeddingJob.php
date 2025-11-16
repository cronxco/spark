<?php

namespace App\Jobs;

use App\Models\Block;
use App\Services\EmbeddingService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateBlockEmbeddingJob implements ShouldQueue
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
        public Block $block
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EmbeddingService $embeddingService): void
    {
        try {
            // Get searchable text from block
            $searchableText = $this->block->getSearchableText();

            if (empty(trim($searchableText))) {
                Log::warning('Block has no searchable text, skipping embedding generation', [
                    'block_id' => $this->block->id,
                ]);

                return;
            }

            // Generate embedding
            $embedding = $embeddingService->embed($searchableText);

            // Get embedding metadata
            $embeddingMetadata = $embeddingService->getEmbeddingMetadata();

            // Merge embedding metadata into block metadata
            $metadata = $this->block->metadata ?? [];
            $metadata = array_merge($metadata, $embeddingMetadata);

            // Store embedding and metadata in database
            $this->block->update([
                'embeddings' => EmbeddingService::formatForPostgres($embedding),
                'metadata' => $metadata,
            ]);

            Log::info('Generated embedding for block', [
                'block_id' => $this->block->id,
                'text_length' => strlen($searchableText),
                'model' => $embeddingMetadata['embedding_model'],
            ]);
        } catch (Exception $e) {
            Log::error('Failed to generate embedding for block', [
                'block_id' => $this->block->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('GenerateBlockEmbeddingJob failed after all retries', [
            'block_id' => $this->block->id,
            'error' => $exception?->getMessage(),
        ]);
    }
}
