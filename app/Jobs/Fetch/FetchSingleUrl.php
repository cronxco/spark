<?php

namespace App\Jobs\Fetch;

use App\Integrations\Fetch\ArchiveBypassHandler;
use App\Integrations\Fetch\ArticleImageExtractor;
use App\Integrations\Fetch\ContentExtractor;
use App\Integrations\Fetch\FetchEngineManager;
use App\Jobs\Data\Fetch\ProcessFetchedContent;
use App\Models\EventObject;
use App\Models\Integration;
use App\Notifications\FetchMultipleFailures;
use App\Services\Media\MediaDownloadHelper;
use App\Services\PlaywrightHealthMetrics;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchSingleUrl implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [60, 300, 900]; // 1min, 5min, 15min

    public function __construct(
        public Integration $integration,
        public string $webpageObjectId,
        public string $url,
        public bool $forceRefresh = false
    ) {}

    public function handle(): void
    {
        $startTime = microtime(true);

        Log::info('Fetch: Fetching single URL', [
            'integration_id' => $this->integration->id,
            'webpage_id' => $this->webpageObjectId,
            'url' => $this->url,
        ]);

        $webpage = EventObject::find($this->webpageObjectId);
        if (! $webpage) {
            Log::error('Fetch: Webpage object not found', [
                'webpage_id' => $this->webpageObjectId,
            ]);

            return;
        }

        $group = $this->integration->group;
        if (! $group) {
            Log::error('Fetch: Integration group not found', [
                'integration_id' => $this->integration->id,
            ]);
            $this->updateWebpageError($webpage, 'Integration group not found');

            return;
        }

        $engine = new FetchEngineManager;

        try {
            // Check if URL is a PDF
            if (str_ends_with(strtolower($this->url), '.pdf')) {
                $this->handlePdf($webpage);

                return;
            }

            // Fetch URL using engine manager (auto-selects between Playwright and HTTP)
            $result = $engine->fetch($this->url, $group, $webpage);

            if ($result['error']) {
                throw new Exception($result['error']);
            }

            $html = $result['html'];
            $statusCode = $result['status_code'];
            $screenshot = $result['screenshot'] ?? null;
            $method = $result['method'] ?? 'unknown';

            // Calculate duration
            $durationMs = round((microtime(true) - $startTime) * 1000);

            Log::debug('Fetch: Response received', [
                'url' => $this->url,
                'status_code' => $statusCode,
                'content_length' => strlen($html),
                'method' => $method,
                'has_screenshot' => ! empty($screenshot),
                'duration_ms' => $durationMs,
            ]);

            // Extract content using Readability
            $extraction = ContentExtractor::extract($html, $this->url);

            if (! $extraction['success']) {
                $reason = $extraction['reason'] ?? 'Unknown error';
                Log::warning('Fetch: Content extraction failed', [
                    'url' => $this->url,
                    'reason' => $reason,
                ]);

                // Check if this is a paywall failure and attempt archive.is bypass
                if (str_contains(strtolower($reason), 'paywall') &&
                    ArchiveBypassHandler::shouldAttemptBypass($this->url)) {

                    Log::info('Fetch: Attempting archive.is bypass for paywalled content', [
                        'url' => $this->url,
                    ]);

                    $archiveResult = $this->attemptArchiveBypass($this->url);

                    if ($archiveResult['success']) {
                        // Re-extract content from archive
                        $archiveExtraction = ContentExtractor::extract($archiveResult['html'], $this->url);

                        if ($archiveExtraction['success']) {
                            Log::info('Fetch: Archive bypass successful', [
                                'url' => $this->url,
                                'archive_url' => $archiveResult['archive_url'],
                            ]);

                            // Use the archived content
                            $extraction = $archiveExtraction;
                            $html = $archiveResult['html'];

                            // Update metadata to track archive usage
                            $metadata = $webpage->metadata ?? [];
                            $metadata['last_archive_bypass'] = [
                                'timestamp' => now()->toIso8601String(),
                                'archive_url' => $archiveResult['archive_url'],
                            ];
                            $webpage->update(['metadata' => $metadata]);

                            // Update history with archive success
                            $engine->updateLastHistoryEntry($webpage, [
                                'outcome' => 'success_via_archive',
                                'duration_ms' => $durationMs,
                                'status_code' => $statusCode,
                                'archive_url' => $archiveResult['archive_url'],
                            ]);

                            // Continue with the successful extraction below
                            goto extraction_success;
                        }
                    }

                    Log::debug('Fetch: Archive bypass did not yield usable content', [
                        'url' => $this->url,
                    ]);
                }

                $this->updateWebpageError($webpage, $reason);

                // Update history with failure
                $engine->updateLastHistoryEntry($webpage, [
                    'outcome' => 'failed',
                    'duration_ms' => $durationMs,
                    'status_code' => $statusCode,
                ]);

                return;
            }

            extraction_success:

            $extracted = $extraction['data'];

            // Generate content hash
            $contentHash = ContentExtractor::generateHash($extracted['text_content']);

            Log::info('Fetch: Content extracted successfully', [
                'url' => $this->url,
                'title' => $extracted['title'],
                'content_length' => strlen($extracted['text_content']),
                'content_hash' => substr($contentHash, 0, 8),
                'method' => $method,
            ]);

            // Handle images based on fetch mode
            $this->handleImages($webpage, $html, $screenshot);

            // Update webpage metadata with fetch method
            $metadata = $webpage->metadata ?? [];
            $metadata['last_fetch_method'] = $method;
            $webpage->update(['metadata' => $metadata]);

            // Update history with success
            $engine->updateLastHistoryEntry($webpage, [
                'outcome' => 'success',
                'duration_ms' => $durationMs,
                'status_code' => $statusCode,
            ]);

            // Record metrics
            $metrics = new PlaywrightHealthMetrics;
            $metrics->recordFetch($method, true, (int) $durationMs);

            // Check if stealth was used and effective (for robot detection)
            if ($method === 'playwright' && isset($webpage->metadata['last_error'])) {
                $lastError = $webpage->metadata['last_error'];
                $errorMsg = strtolower($lastError['message'] ?? '');
                if (str_contains($errorMsg, 'robot') || str_contains($errorMsg, 'captcha')) {
                    $metrics->recordStealthBypass(true);
                }
            }

            // Dispatch processing job
            ProcessFetchedContent::dispatch(
                $this->integration,
                $webpage,
                $extracted,
                $contentHash,
                $this->forceRefresh
            );
        } catch (GuzzleException $e) {
            $durationMs = round((microtime(true) - $startTime) * 1000);

            Log::error('Fetch: HTTP request failed', [
                'url' => $this->url,
                'error' => $e->getMessage(),
            ]);
            $this->updateWebpageError($webpage, 'HTTP error: ' . $e->getMessage());

            // Update history with failure
            $engine->updateLastHistoryEntry($webpage, [
                'outcome' => 'failed',
                'duration_ms' => $durationMs,
                'status_code' => 0,
            ]);

            // Record metrics
            $metrics = new PlaywrightHealthMetrics;
            $method = $webpage->metadata['last_fetch_method'] ?? 'http';
            $metrics->recordFetch($method, false, (int) $durationMs, 'http_error');

            // Check for bot detection
            if (str_contains(strtolower($e->getMessage()), 'robot') || str_contains(strtolower($e->getMessage()), 'captcha')) {
                $metrics->recordStealthBypass(false);
            }

            throw $e; // Re-throw to trigger retry
        } catch (Exception $e) {
            $durationMs = round((microtime(true) - $startTime) * 1000);

            Log::error('Fetch: Unexpected error', [
                'url' => $this->url,
                'error' => $e->getMessage(),
            ]);
            $this->updateWebpageError($webpage, 'Error: ' . $e->getMessage());

            // Update history with failure
            $engine->updateLastHistoryEntry($webpage, [
                'outcome' => 'failed',
                'duration_ms' => $durationMs,
                'status_code' => 0,
            ]);

            // Record metrics
            $metrics = new PlaywrightHealthMetrics;
            $method = $webpage->metadata['last_fetch_method'] ?? 'http';
            $metrics->recordFetch($method, false, (int) $durationMs, 'general_error');

            throw $e; // Re-throw to trigger retry
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Fetch: Job failed after all retries', [
            'url' => $this->url,
            'webpage_id' => $this->webpageObjectId,
            'error' => $exception->getMessage(),
        ]);

        $webpage = EventObject::find($this->webpageObjectId);
        if ($webpage) {
            $this->updateWebpageError($webpage, 'Failed after 3 attempts: ' . $exception->getMessage());
        }
    }

    /**
     * Attempt to fetch content via archive.is bypass
     *
     * @return array ['success' => bool, 'html' => ?string, 'archive_url' => ?string, 'error' => ?string]
     */
    private function attemptArchiveBypass(string $url): array
    {
        try {
            $handler = new ArchiveBypassHandler;

            return $handler->fetchFromArchive($url);
        } catch (Exception $e) {
            Log::warning('Fetch: Archive bypass exception', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'html' => null,
                'archive_url' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function handlePdf(EventObject $webpage): void
    {
        Log::info('Fetch: PDF detected, downloading', [
            'url' => $this->url,
        ]);

        try {
            // Download and attach PDF using Media Library with deduplication
            $mediaHelper = app(MediaDownloadHelper::class);

            $mediaHelper->downloadAndAttachMedia(
                $this->url,
                $webpage,
                'pdfs'
            );

            Log::info('Fetch: PDF downloaded successfully', [
                'url' => $this->url,
            ]);

            // Update webpage metadata
            $metadata = $webpage->metadata ?? [];
            $metadata['last_checked_at'] = now()->toIso8601String();
            $metadata['fetch_count'] = ($metadata['fetch_count'] ?? 0) + 1;
            $metadata['last_error'] = null;
            $webpage->update(['metadata' => $metadata]);
        } catch (Exception $e) {
            Log::error('Fetch: PDF download failed', [
                'url' => $this->url,
                'error' => $e->getMessage(),
            ]);
            $this->updateWebpageError($webpage, 'PDF download failed: ' . $e->getMessage());
        }
    }

    private function updateWebpageError(EventObject $webpage, string $errorMessage): void
    {
        $metadata = $webpage->metadata ?? [];
        $metadata['last_checked_at'] = now()->toIso8601String();
        $metadata['fetch_count'] = ($metadata['fetch_count'] ?? 0) + 1;
        $consecutiveFailures = ($metadata['last_error']['consecutive_failures'] ?? 0) + 1;
        $metadata['last_error'] = [
            'message' => $errorMessage,
            'timestamp' => now()->toIso8601String(),
            'consecutive_failures' => $consecutiveFailures,
        ];

        // Send notification after 3 consecutive failures
        if ($consecutiveFailures === 3) {
            $this->integration->user->notify(
                new FetchMultipleFailures($webpage, $consecutiveFailures, $errorMessage)
            );

            Log::info('Fetch: Sent multiple failures notification', [
                'url' => $this->url,
                'webpage_id' => $webpage->id,
                'consecutive_failures' => $consecutiveFailures,
            ]);
        }

        // Auto-disable after 5 consecutive failures
        if ($consecutiveFailures >= 5) {
            $metadata['enabled'] = false;
            Log::warning('Fetch: Auto-disabled URL after 5 failures', [
                'url' => $this->url,
                'webpage_id' => $webpage->id,
            ]);
        }

        $webpage->update(['metadata' => $metadata]);
    }

    /**
     * Handle image extraction and storage based on fetch mode.
     *
     * - For 'once' (fetch-once): Save screenshot AND extract article image (article image is primary)
     * - For 'recurring' (subscribed): Only extract article image, no screenshots
     */
    private function handleImages(EventObject $webpage, string $html, ?string $screenshot): void
    {
        $fetchMode = $webpage->metadata['fetch_mode'] ?? 'once';
        $mediaHelper = app(MediaDownloadHelper::class);

        Log::debug('Fetch: Handling images', [
            'url' => $this->url,
            'fetch_mode' => $fetchMode,
            'has_screenshot' => ! empty($screenshot),
        ]);

        // Extract article image (for both fetch modes)
        $articleImageUrl = ArticleImageExtractor::extract($html, $this->url);

        if ($articleImageUrl) {
            try {
                $media = $mediaHelper->downloadAndAttachMedia(
                    $articleImageUrl,
                    $webpage,
                    'article_images'
                );

                if ($media) {
                    Log::info('Fetch: Article image saved', [
                        'url' => $this->url,
                        'image_url' => $articleImageUrl,
                        'media_uuid' => $media->uuid,
                    ]);

                    // Also update media_url field on the bookmark for backward compatibility
                    $webpage->update(['media_url' => $articleImageUrl]);
                }
            } catch (Exception $e) {
                Log::warning('Fetch: Failed to save article image', [
                    'url' => $this->url,
                    'image_url' => $articleImageUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            Log::debug('Fetch: No article image found', ['url' => $this->url]);
        }

        // Store screenshot only for 'once' fetch mode (fetch-once bookmarks)
        if ($fetchMode === 'once' && $screenshot) {
            try {
                $fileName = 'screenshot-' . now()->format('Y-m-d-His') . '.png';

                $mediaHelper->attachMediaFromBase64(
                    $screenshot,
                    $webpage,
                    $fileName,
                    'screenshots'
                );

                Log::debug('Fetch: Screenshot saved', ['url' => $this->url]);
            } catch (Exception $e) {
                Log::warning('Fetch: Failed to save screenshot', [
                    'url' => $this->url,
                    'error' => $e->getMessage(),
                ]);
            }
        } elseif ($fetchMode === 'recurring' && $screenshot) {
            Log::debug('Fetch: Skipping screenshot for recurring fetch mode', ['url' => $this->url]);
        }
    }
}
