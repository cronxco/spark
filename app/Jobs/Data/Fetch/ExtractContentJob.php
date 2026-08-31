<?php

namespace App\Jobs\Data\Fetch;

use App\Jobs\Concerns\EnhancedIdempotency;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Services\Ai\Knowledge\ContentExtractor;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractContentJob implements ShouldQueue
{
    use Dispatchable, EnhancedIdempotency, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes for AI processing

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public function __construct(
        public Integration $integration,
        public ?Event $event,
        public EventObject $webpage,
        public array $extracted,
        public ?string $sourceObjectId = null,
        public ?string $sourceEventId = null,
        public bool $sourceIsObject = false
    ) {}

    public function handle(): void
    {
        Log::info('Fetch: Extracting article content with AI', [
            'integration_id' => $this->integration->id,
            'event_id' => $this->event?->id,
            'webpage_id' => $this->webpage->id,
            'url' => $this->webpage->url,
            'has_source_object' => ! is_null($this->sourceObjectId),
            'has_source_event' => ! is_null($this->sourceEventId),
        ]);

        try {
            // Extract clean article text using AI
            $articleText = $this->extractArticleText(
                $this->extracted['title'],
                $this->extracted['text_content']
            );

            // If this is a linkable discovered URL, update the source object's content and title
            if ($this->sourceObjectId) {
                $sourceObject = EventObject::find($this->sourceObjectId);
                if ($sourceObject) {
                    $sourceObject->title = $this->extracted['title'];
                    $sourceObject->content = $articleText;
                    $sourceObject->save();

                    // Lock the object to prevent further automatic updates
                    $sourceObject->lock();

                    Log::info('Fetch: Updated and locked source EventObject', [
                        'source_object_id' => $this->sourceObjectId,
                        'title' => $this->extracted['title'],
                        'word_count' => str_word_count($articleText),
                    ]);
                }
            }

            // Store extracted markdown content in webpage EventObject content field
            $this->webpage->content = $articleText;
            $this->webpage->save();

            Log::info('Fetch: Article text extracted successfully', [
                'event_id' => $this->event?->id,
                'word_count' => str_word_count($articleText),
            ]);

            // Check if this is a one-time fetch that's already completed
            $metadata = $this->webpage->metadata ?? [];
            $fetchMode = $metadata['fetch_mode'] ?? 'recurring';
            $discoveryStatus = $metadata['discovery_status'] ?? 'pending';

            if ($fetchMode === 'once' && $discoveryStatus === 'completed') {
                Log::info('Fetch: Skipping summary generation - one-time bookmark already completed', [
                    'webpage_id' => $this->webpage->id,
                    'url' => $this->webpage->url,
                ]);

                return;
            }

            // Dispatch summary generation job
            GenerateSummariesJob::dispatch(
                $this->integration,
                $this->event,
                $this->webpage,
                $this->extracted,
                $articleText,
                $this->sourceObjectId,
                $this->sourceEventId,
                $this->sourceIsObject
            );

            Log::info('Fetch: Dispatched summary generation job', [
                'event_id' => $this->event?->id,
                'has_source_object' => ! is_null($this->sourceObjectId),
                'has_source_event' => ! is_null($this->sourceEventId),
            ]);
        } catch (Exception $e) {
            Log::error('Fetch: Content extraction failed', [
                'url' => $this->webpage->url,
                'event_id' => $this->event?->id,
                'error' => $e->getMessage(),
            ]);

            // Update webpage metadata with error
            $metadata = $this->webpage->metadata ?? [];
            $metadata['last_extraction_error'] = $e->getMessage();
            $metadata['last_extraction_error_at'] = now()->toIso8601String();
            $this->webpage->update(['metadata' => $metadata]);

            throw $e;
        }
    }

    public function uniqueId(): string
    {
        return 'extract_content_' . $this->integration->id . '_' . ($this->event?->id ?? 'linkable_' . $this->webpage->id);
    }

    private function extractArticleText(string $title, string $content): string
    {
        return app(ContentExtractor::class)->extract(
            'knowledge/extract-article',
            'title',
            $title,
            $content,
            ['url' => $this->webpage->url, 'integration_id' => $this->integration->id],
        );
    }
}
