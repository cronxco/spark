<?php

namespace App\Jobs\Media;

use App\Models\ActionProgress;
use App\Models\Block;
use App\Models\EventObject;
use App\Services\Media\MediaDownloadHelper;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MigrateExternalMediaUrlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [60, 300, 900]; // 1min, 5min, 15min

    public $timeout = 120; // 2 minutes per file

    public function __construct(
        public string $modelClass,
        public string $modelId,
        public string $mediaUrl,
        public ?string $progressId = null
    ) {}

    public function handle(MediaDownloadHelper $mediaHelper): void
    {
        try {
            // Find the model
            $model = $this->modelClass::find($this->modelId);

            if (! $model) {
                Log::warning('Media migration: Model not found', [
                    'model_class' => $this->modelClass,
                    'model_id' => $this->modelId,
                ]);

                $this->updateProgress(failed: true);

                return;
            }

            // Check if media_url still exists (might have been cleared by another job)
            if (empty($model->media_url)) {
                Log::debug('Media migration: media_url already cleared', [
                    'model_class' => $this->modelClass,
                    'model_id' => $this->modelId,
                ]);

                $this->updateProgress();

                return;
            }

            // Determine collection based on model type and content
            $collection = $this->determineCollection($model);

            // Download and attach media
            $media = $mediaHelper->downloadAndAttachMedia(
                $this->mediaUrl,
                $model,
                $collection,
                ['migrated_from_url' => true]
            );

            if ($media) {
                Log::info('Media migration: Successfully migrated media', [
                    'model_class' => $this->modelClass,
                    'model_id' => $this->modelId,
                    'media_uuid' => $media->uuid,
                    'media_url' => $this->mediaUrl,
                    'collection' => $collection,
                ]);

                // Keep media_url for now (can be cleared later if needed)
                // $model->update(['media_url' => null]);

                $this->updateProgress();
            } else {
                Log::warning('Media migration: Failed to download media', [
                    'model_class' => $this->modelClass,
                    'model_id' => $this->modelId,
                    'media_url' => $this->mediaUrl,
                ]);

                $this->updateProgress(failed: true);
            }
        } catch (Exception $e) {
            Log::error('Media migration: Exception occurred', [
                'model_class' => $this->modelClass,
                'model_id' => $this->modelId,
                'media_url' => $this->mediaUrl,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            $this->updateProgress(failed: true);

            throw $e; // Re-throw for retry logic
        }
    }

    protected function determineCollection($model): string
    {
        // For EventObject
        if ($model instanceof EventObject) {
            // Check if it's a screenshot (from Playwright/Fetch)
            if (isset($model->metadata['has_screenshot']) || $model->type === 'fetch_webpage') {
                return 'screenshots';
            }

            // Check if it's a PDF
            if (isset($model->metadata['content_type']) && str_contains($model->metadata['content_type'], 'pdf')) {
                return 'pdfs';
            }

            // Default to downloaded_images
            return 'downloaded_images';
        }

        // For Block
        if ($model instanceof Block) {
            // Check if block type suggests a specific type
            if (str_contains($model->block_type ?? '', 'video')) {
                return 'downloaded_videos';
            }

            if (str_contains($model->block_type ?? '', 'document') || str_contains($model->block_type ?? '', 'pdf')) {
                return 'downloaded_documents';
            }

            // Default to downloaded_images
            return 'downloaded_images';
        }

        return 'downloaded_images';
    }

    protected function updateProgress(bool $failed = false): void
    {
        if (! $this->progressId) {
            return;
        }

        $progress = ActionProgress::find($this->progressId);

        if (! $progress) {
            return;
        }

        if ($failed) {
            $progress->increment('failed');
        }

        $progress->increment('processed');

        // Check if complete
        if ($progress->processed >= $progress->total) {
            $progress->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        }
    }
}
