<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Models\Event;
use App\Services\EmbeddingService;
use Illuminate\Support\Facades\Log;

class GenerateEmbeddingTask extends BaseTaskJob
{
    /**
     * Execute the embedding generation task
     */
    protected function execute(): void
    {
        $embeddingService = app(EmbeddingService::class);

        // Get searchable text from the model using its getSearchableText() method
        $searchableText = $this->model->getSearchableText();

        if (empty(trim($searchableText))) {
            Log::warning('Model has no searchable text, skipping embedding generation', [
                'model_type' => get_class($this->model),
                'model_id' => $this->model->id,
            ]);

            return;
        }

        // Generate embedding
        $embedding = $embeddingService->embed($searchableText);

        // Get embedding metadata
        $embeddingMetadata = $embeddingService->getEmbeddingMetadata();

        // Determine metadata field name (Events use 'event_metadata', others use 'metadata')
        $metadataField = $this->model instanceof Event ? 'event_metadata' : 'metadata';

        // Merge embedding metadata into model metadata
        $metadata = $this->model->$metadataField ?? [];
        $metadata = array_merge($metadata, $embeddingMetadata);

        // Store embedding and metadata in database
        // Use withoutEvents() to prevent observers from triggering on this internal update
        $this->model->withoutEvents(function () use ($embedding, $metadata, $metadataField) {
            $this->model->update([
                'embeddings' => EmbeddingService::formatForPostgres($embedding),
                $metadataField => $metadata,
            ]);
        });

        Log::info('Generated embedding via TaskPipeline', [
            'model_type' => get_class($this->model),
            'model_id' => $this->model->id,
            'text_length' => strlen($searchableText),
            'embedding_model' => $embeddingMetadata['embedding_model'],
        ]);
    }
}
