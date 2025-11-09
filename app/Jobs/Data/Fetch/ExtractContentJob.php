<?php

namespace App\Jobs\Data\Fetch;

use App\Jobs\Concerns\EnhancedIdempotency;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

class ExtractContentJob implements ShouldQueue
{
    use Dispatchable, EnhancedIdempotency, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes for AI processing

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public function __construct(
        public Integration $integration,
        public Event $event,
        public EventObject $webpage,
        public array $extracted
    ) {}

    public function handle(): void
    {
        Log::info('Fetch: Extracting article content with AI', [
            'integration_id' => $this->integration->id,
            'event_id' => $this->event->id,
            'webpage_id' => $this->webpage->id,
            'url' => $this->webpage->url,
        ]);

        try {
            // Extract clean article text using AI
            $articleText = $this->extractArticleText(
                $this->extracted['title'],
                $this->extracted['text_content']
            );

            // Create Block 2: Article Text
            $this->event->createBlock([
                'title' => 'Article Text',
                'block_type' => 'fetch_article_text',
                'time' => $this->event->time,
                'metadata' => [
                    'article_text' => $articleText,
                    'word_count' => str_word_count($articleText),
                    'char_count' => strlen($articleText),
                    'generated_at' => now()->toIso8601String(),
                    'model' => 'gpt-5-nano',
                ],
            ]);

            // Store extracted content in EventObject content field
            $this->webpage->content = $articleText;
            $this->webpage->save();

            Log::info('Fetch: Article text extracted successfully', [
                'event_id' => $this->event->id,
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
                $articleText
            );

            Log::info('Fetch: Dispatched summary generation job', [
                'event_id' => $this->event->id,
            ]);
        } catch (Exception $e) {
            Log::error('Fetch: Content extraction failed', [
                'url' => $this->webpage->url,
                'event_id' => $this->event->id,
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
        return 'extract_content_' . $this->integration->id . '_' . $this->event->id;
    }

    private function extractArticleText(string $title, string $content): string
    {
        $contentLength = strlen($content);
        $maxContentLength = 15000;
        $wasTruncated = $contentLength > $maxContentLength;
        $contentToSend = mb_substr($content, 0, $maxContentLength);

        Log::debug('Fetch: Extracting article text with AI', [
            'title' => $title,
            'content_length' => $contentLength,
            'truncated' => $wasTruncated,
            'truncated_length' => $wasTruncated ? strlen($contentToSend) : null,
        ]);

        if ($wasTruncated) {
            Log::warning('Fetch: Content truncated for AI processing', [
                'url' => $this->webpage->url ?? 'unknown',
                'original_length' => $contentLength,
                'truncated_to' => strlen($contentToSend),
                'characters_lost' => $contentLength - strlen($contentToSend),
                'percentage_sent' => round((strlen($contentToSend) / $contentLength) * 100, 1) . '%',
            ]);
        }

        $systemPrompt = <<<'PROMPT'
You are an intelligent content extractor. Given an article title and raw content, extract and return the clean article text.

Requirements:
1. Remove navigation, ads, footers, cookie notices, and other non-article content
2. Preserve the complete article text including all paragraphs
3. Maintain proper formatting and structure
4. Keep all important content intact
5. Return only the clean article text as a plain string (not JSON)

The output should be the full, clean article text that a reader would want to read.
PROMPT;

        try {
            $result = OpenAI::chat()->create([
                'model' => 'gpt-5-nano',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemPrompt,
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode([
                            'title' => $title,
                            'content' => $contentToSend,
                        ]),
                    ],
                ],
            ]);

            $articleText = trim($result->choices[0]->message->content);

            if (empty($articleText)) {
                throw new Exception('Empty article text returned from AI');
            }

            Log::debug('Fetch: Article text extracted successfully', [
                'original_length' => $contentLength,
                'extracted_length' => strlen($articleText),
            ]);

            return $articleText;
        } catch (Exception $e) {
            Log::error('Fetch: AI article extraction failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
