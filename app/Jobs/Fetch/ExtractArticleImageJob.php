<?php

namespace App\Jobs\Fetch;

use App\Integrations\Fetch\ArticleImageExtractor;
use App\Integrations\Fetch\FetchEngineManager;
use App\Models\ActionProgress;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Services\Media\MediaDownloadHelper;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to extract and save article images for existing bookmarks.
 *
 * This job is used by the migration command to backfill article images
 * for bookmarks that were created before the article image extraction feature.
 */
class ExtractArticleImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $backoff = [60, 300]; // 1min, 5min

    public $timeout = 120; // 2 minutes

    public function __construct(
        public string $webpageId,
        public ?string $progressId = null
    ) {}

    public function handle(MediaDownloadHelper $mediaHelper): void
    {
        try {
            $webpage = EventObject::find($this->webpageId);

            if (! $webpage) {
                Log::warning('ExtractArticleImage: Webpage not found', [
                    'webpage_id' => $this->webpageId,
                ]);
                $this->updateProgress(failed: true);

                return;
            }

            // Skip if already has an article image
            if ($webpage->hasMedia('article_images')) {
                Log::debug('ExtractArticleImage: Already has article image', [
                    'webpage_id' => $this->webpageId,
                    'url' => $webpage->url,
                ]);
                $this->updateProgress();

                return;
            }

            // Skip if no URL
            if (empty($webpage->url)) {
                Log::debug('ExtractArticleImage: No URL', [
                    'webpage_id' => $this->webpageId,
                ]);
                $this->updateProgress(failed: true);

                return;
            }

            Log::info('ExtractArticleImage: Processing', [
                'webpage_id' => $this->webpageId,
                'url' => $webpage->url,
            ]);

            // Get the integration group for fetching
            $group = $this->getIntegrationGroup($webpage);

            // Fetch the webpage HTML
            $engine = new FetchEngineManager;
            $result = $engine->fetch($webpage->url, $group, $webpage);

            if ($result['error']) {
                Log::warning('ExtractArticleImage: Fetch failed', [
                    'webpage_id' => $this->webpageId,
                    'url' => $webpage->url,
                    'error' => $result['error'],
                ]);
                $this->updateProgress(failed: true);

                return;
            }

            $html = $result['html'];

            // Extract article image
            $articleImageUrl = ArticleImageExtractor::extract($html, $webpage->url);

            if (! $articleImageUrl) {
                Log::debug('ExtractArticleImage: No article image found', [
                    'webpage_id' => $this->webpageId,
                    'url' => $webpage->url,
                ]);
                $this->updateProgress();

                return;
            }

            // Download and save the article image
            $media = $mediaHelper->downloadAndAttachMedia(
                $articleImageUrl,
                $webpage,
                'article_images',
                ['migrated' => true, 'source_url' => $articleImageUrl]
            );

            if ($media) {
                // Also update media_url field on the bookmark for backward compatibility
                $webpage->update(['media_url' => $articleImageUrl]);

                Log::info('ExtractArticleImage: Article image saved', [
                    'webpage_id' => $this->webpageId,
                    'url' => $webpage->url,
                    'image_url' => $articleImageUrl,
                    'media_uuid' => $media->uuid,
                ]);
                $this->updateProgress();
            } else {
                Log::warning('ExtractArticleImage: Failed to download article image', [
                    'webpage_id' => $this->webpageId,
                    'url' => $webpage->url,
                    'image_url' => $articleImageUrl,
                ]);
                $this->updateProgress(failed: true);
            }
        } catch (Exception $e) {
            Log::error('ExtractArticleImage: Exception', [
                'webpage_id' => $this->webpageId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            $this->updateProgress(failed: true);

            throw $e; // Re-throw for retry logic
        }
    }

    /**
     * Get the integration group for the webpage.
     */
    protected function getIntegrationGroup(EventObject $webpage): ?IntegrationGroup
    {
        // Try to get from integration
        if ($webpage->integration_id) {
            $integration = $webpage->integration;
            if ($integration && $integration->group) {
                return $integration->group;
            }
        }

        // Try to get from metadata
        $fetchIntegrationId = $webpage->metadata['fetch_integration_id'] ?? null;
        if ($fetchIntegrationId) {
            $integration = Integration::find($fetchIntegrationId);
            if ($integration && $integration->group) {
                return $integration->group;
            }
        }

        // Get or create default group for user
        $user = $webpage->user;
        if ($user) {
            return IntegrationGroup::firstOrCreate(
                ['user_id' => $user->id, 'name' => 'Default'],
                ['settings' => []]
            );
        }

        return null;
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
