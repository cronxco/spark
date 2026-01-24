<?php

namespace App\Jobs\Data\Newsletter;

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

class ExtractNewsletterContentJob implements ShouldQueue
{
    use Dispatchable, EnhancedIdempotency, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes for AI processing

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public function __construct(
        public Integration $integration,
        public Event $event,
        public EventObject $publication,
        public string $rawContent
    ) {}

    public function handle(): void
    {
        Log::info('Newsletter: Extracting newsletter content with AI', [
            'integration_id' => $this->integration->id,
            'event_id' => $this->event->id,
            'publication_id' => $this->publication->id,
            'publication_title' => $this->publication->title,
        ]);

        try {
            // Extract clean article text using AI
            $articleText = $this->extractArticleText(
                $this->event->event_metadata['email_subject'] ?? 'No Subject',
                $this->rawContent
            );

            // Store extracted markdown content in publication EventObject content field
            $this->publication->content = $articleText;

            // Update publication metadata
            $metadata = $this->publication->metadata ?? [];
            $metadata['extracted_at'] = now()->toIso8601String();
            $this->publication->metadata = $metadata;

            $this->publication->save();

            Log::info('Newsletter: Article text extracted successfully', [
                'event_id' => $this->event->id,
                'publication_id' => $this->publication->id,
                'word_count' => str_word_count($articleText),
            ]);

            // Dispatch summary generation job
            GenerateNewsletterSummariesJob::dispatch(
                $this->integration,
                $this->event,
                $this->publication,
                $articleText
            );

            Log::info('Newsletter: Dispatched summary generation job', [
                'event_id' => $this->event->id,
                'publication_id' => $this->publication->id,
            ]);
        } catch (Exception $e) {
            Log::error('Newsletter: Content extraction failed', [
                'event_id' => $this->event->id,
                'publication_id' => $this->publication->id,
                'error' => $e->getMessage(),
            ]);

            // Update event metadata with error
            $metadata = $this->event->event_metadata ?? [];
            $metadata['last_extraction_error'] = $e->getMessage();
            $metadata['last_extraction_error_at'] = now()->toIso8601String();
            $this->event->update(['event_metadata' => $metadata]);

            throw $e;
        }
    }

    public function uniqueId(): string
    {
        return 'extract_newsletter_content_'.$this->integration->id.'_'.$this->event->id;
    }

    private function extractArticleText(string $subject, string $content): string
    {
        $contentLength = mb_strlen($content);
        $maxContentLength = 150000;
        $wasTruncated = $contentLength > $maxContentLength;
        $contentToSend = mb_substr($content, 0, $maxContentLength);

        Log::debug('Newsletter: Extracting article text with AI', [
            'subject' => $subject,
            'content_length' => $contentLength,
            'truncated' => $wasTruncated,
            'truncated_length' => $wasTruncated ? strlen($contentToSend) : null,
        ]);

        if ($wasTruncated) {
            Log::warning('Newsletter: Content truncated for AI processing', [
                'event_id' => $this->event->id,
                'original_length' => $contentLength,
                'truncated_to' => strlen($contentToSend),
                'characters_lost' => $contentLength - strlen($contentToSend),
                'percentage_sent' => round((strlen($contentToSend) / $contentLength) * 100, 1).'%',
            ]);
        }

        $systemPrompt = <<<'PROMPT'
You are an intelligent newsletter content extractor. Given a newsletter email HTML or text, extract and return the clean article text formatted in Markdown.

**IMPORTANT**: Your output MUST be formatted in Markdown with appropriate formatting (headings, bold, italic, links, lists, quotes, code blocks, etc.) to enhance readability.

Requirements:
1. Remove email headers, footers, unsubscribe links, social media buttons, and other email-specific content
2. Preserve the complete article/newsletter text including all paragraphs
3. Format the content using proper Markdown syntax:
   - Use # ## ### for headings
   - Use **bold** and *italic* for emphasis
   - Use > for blockquotes
   - Use - or * for unordered lists, 1. 2. 3. for ordered lists
   - Use [text](url) for links
   - Use `code` for inline code, ``` for code blocks
4. Keep all important content intact
5. Return only the clean article text as Markdown (not JSON)

The output should be the full, clean newsletter content in Markdown format that a reader would want to read.
PROMPT;

        try {
            // Start Sentry AI request span
            $model = 'gpt-5-nano';
            $messages = [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'subject' => $subject,
                        'content' => $contentToSend,
                    ]),
                ],
            ];
            $aiSpan = start_ai_request_span($model, $messages, []);

            $result = OpenAI::chat()->create([
                'model' => $model,
                'messages' => $messages,
            ]);

            // Finish AI request span with token usage
            $usage = $result->usage ? $result->usage->toArray() : [];
            $finishReason = $result->choices[0]->finishReason ?? null;
            finish_ai_request_span($aiSpan, $usage, $finishReason);

            $articleText = trim($result->choices[0]->message->content);

            if (empty($articleText)) {
                throw new Exception('Empty article text returned from AI');
            }

            Log::debug('Newsletter: Article text extracted successfully', [
                'original_length' => $contentLength,
                'extracted_length' => strlen($articleText),
            ]);

            return $articleText;
        } catch (Exception $e) {
            Log::error('Newsletter: AI article extraction failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
