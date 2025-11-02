<?php

namespace App\Jobs\Fetch;

use App\Integrations\Fetch\ContentExtractor;
use App\Integrations\Fetch\FetchHttpClient;
use App\Jobs\Data\Fetch\ProcessFetchedContent;
use App\Models\EventObject;
use App\Models\Integration;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchSingleUrl implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [60, 300, 900]; // 1min, 5min, 15min

    public function __construct(
        public Integration $integration,
        public string $webpageObjectId,
        public string $url
    ) {}

    public function handle(): void
    {
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

        try {
            // Check if URL is a PDF
            if (str_ends_with(strtolower($this->url), '.pdf')) {
                $this->handlePdf($webpage);

                return;
            }

            // Fetch URL with cookies
            $response = FetchHttpClient::fetchWithCookies($this->url, $group);
            $html = (string) $response->getBody();
            $statusCode = $response->getStatusCode();

            Log::debug('Fetch: HTTP response received', [
                'url' => $this->url,
                'status_code' => $statusCode,
                'content_length' => strlen($html),
            ]);

            // Extract content using Readability
            $extraction = ContentExtractor::extract($html, $this->url);

            if (! $extraction['success']) {
                $reason = $extraction['reason'] ?? 'Unknown error';
                Log::warning('Fetch: Content extraction failed', [
                    'url' => $this->url,
                    'reason' => $reason,
                ]);
                $this->updateWebpageError($webpage, $reason);

                return;
            }

            $extracted = $extraction['data'];

            // Generate content hash
            $contentHash = ContentExtractor::generateHash($extracted['text_content']);

            Log::info('Fetch: Content extracted successfully', [
                'url' => $this->url,
                'title' => $extracted['title'],
                'content_length' => strlen($extracted['text_content']),
                'content_hash' => substr($contentHash, 0, 8),
            ]);

            // Dispatch processing job
            ProcessFetchedContent::dispatch(
                $this->integration,
                $webpage,
                $extracted,
                $contentHash
            );
        } catch (GuzzleException $e) {
            Log::error('Fetch: HTTP request failed', [
                'url' => $this->url,
                'error' => $e->getMessage(),
            ]);
            $this->updateWebpageError($webpage, 'HTTP error: ' . $e->getMessage());

            throw $e; // Re-throw to trigger retry
        } catch (Exception $e) {
            Log::error('Fetch: Unexpected error', [
                'url' => $this->url,
                'error' => $e->getMessage(),
            ]);
            $this->updateWebpageError($webpage, 'Error: ' . $e->getMessage());

            throw $e; // Re-throw to trigger retry
        }
    }

    public function failed(Exception $exception): void
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

    private function handlePdf(EventObject $webpage): void
    {
        Log::info('Fetch: PDF detected, downloading', [
            'url' => $this->url,
        ]);

        try {
            // Download and attach PDF using Laravel Media Library
            $webpage->addMediaFromUrl($this->url)
                ->toMediaCollection('PDFs');

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
        $metadata['last_error'] = [
            'message' => $errorMessage,
            'timestamp' => now()->toIso8601String(),
            'consecutive_failures' => ($metadata['last_error']['consecutive_failures'] ?? 0) + 1,
        ];

        // Auto-disable after 5 consecutive failures
        if ($metadata['last_error']['consecutive_failures'] >= 5) {
            $metadata['enabled'] = false;
            Log::warning('Fetch: Auto-disabled URL after 5 failures', [
                'url' => $this->url,
                'webpage_id' => $webpage->id,
            ]);
        }

        $webpage->update(['metadata' => $metadata]);
    }
}
