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

class GenerateNewsletterSummariesJob implements ShouldQueue
{
    use Dispatchable, EnhancedIdempotency, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes for AI processing

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public function __construct(
        public Integration $integration,
        public Event $event,
        public EventObject $publication,
        public string $articleText
    ) {}

    public function handle(): void
    {
        Log::info('Newsletter: Generating summaries with AI', [
            'integration_id' => $this->integration->id,
            'event_id' => $this->event->id,
            'publication_id' => $this->publication->id,
        ]);

        try {
            // Generate summaries using AI
            $summaries = $this->generateSummaries(
                $this->event->event_metadata['email_subject'] ?? 'No Subject',
                $this->articleText
            );

            // Create summary blocks on the newsletter event
            $this->createSummaryBlocks($summaries);

            // Attach tags to publication EventObject and event
            if (! empty($summaries['emoji']) || ! empty($summaries['tags'])) {
                $this->attachTags($summaries);
            }

            Log::info('Newsletter: Summaries generated successfully', [
                'event_id' => $this->event->id,
                'publication_id' => $this->publication->id,
            ]);
        } catch (Exception $e) {
            Log::error('Newsletter: Summary generation failed', [
                'event_id' => $this->event->id,
                'publication_id' => $this->publication->id,
                'error' => $e->getMessage(),
            ]);

            // Update event metadata with error
            $metadata = $this->event->event_metadata ?? [];
            $metadata['last_summary_error'] = $e->getMessage();
            $metadata['last_summary_error_at'] = now()->toIso8601String();
            $this->event->update(['event_metadata' => $metadata]);

            throw $e;
        }
    }

    public function uniqueId(): string
    {
        return 'generate_newsletter_summaries_' . $this->integration->id . '_' . $this->event->id;
    }

    private function generateSummaries(string $subject, string $articleText): array
    {
        $contentLength = strlen($articleText);
        $maxContentLength = 150000;
        $wasTruncated = $contentLength > $maxContentLength;
        $contentToSend = mb_substr($articleText, 0, $maxContentLength);

        Log::debug('Newsletter: Generating AI summaries', [
            'subject' => $subject,
            'content_length' => $contentLength,
            'truncated' => $wasTruncated,
            'truncated_length' => $wasTruncated ? strlen($contentToSend) : null,
        ]);

        if ($wasTruncated) {
            Log::warning('Newsletter: Article text truncated for summary generation', [
                'event_id' => $this->event->id,
                'original_length' => $contentLength,
                'truncated_to' => strlen($contentToSend),
                'characters_lost' => $contentLength - strlen($contentToSend),
                'percentage_sent' => round((strlen($contentToSend) / $contentLength) * 100, 1) . '%',
            ]);
        }

        $systemPrompt = <<<'PROMPT'
You are an intelligent content summarizer. Given an article title and clean article text, provide exactly 7 different outputs in JSON.

**IMPORTANT**: All text outputs MUST be formatted in Markdown. Use appropriate formatting (bold, italic, links, lists) to enhance readability.

Requirements:
1. summary_tweet: 280 characters maximum, ultra-concise, engaging (Markdown formatted)
2. summary_short: No more than 40 words, concise overview (Markdown formatted)
3. summary_paragraph: No more than 150 words, detailed overview with key points (Markdown formatted)
4. key_takeaways: Array of 3-5 strings, each a bullet point with key insights (can include bold, links)
5. tldr: Single sentence (max 20 words), absolute minimum summary (Markdown formatted)
6. emoji: Single emoji that best represents the article's theme or content
7. tags: Array of 1-5 semantic tags with types. Only include tags that are clearly relevant and mentioned in the content:
   - "topic-tag" for subjects/themes (e.g., "Machine Learning", "Climate Change")
   - "person-tag" for people mentioned (e.g., "Elon Musk", "Jane Doe")
   - "organisation-tag" for organizations (e.g., "NASA", "Microsoft")
   - "place-tag" for locations (e.g., "New York", "Mars")

Return ONLY valid JSON in this exact format:
{
  "summary_tweet": "**Markdown formatted** 280 char version here",
  "summary_short": "Markdown formatted 40 word version here",
  "summary_paragraph": "Markdown formatted 150 word version here with **bold** and *italic*",
  "key_takeaways": ["**Bold point 1** with details", "Point 2 with [link](url)", "Point 3"],
  "tldr": "Markdown formatted one sentence version here",
  "emoji": "📰",
  "tags": [
    {"tag": "Artificial Intelligence", "tag_type": "topic-tag"},
    {"tag": "Sam Altman", "tag_type": "person-tag"}
  ]
}
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
                        'title' => $subject,
                        'article_text' => $contentToSend,
                    ]),
                ],
            ];
            $aiSpan = start_ai_request_span($model, $messages, []);

            $result = OpenAI::chat()->create([
                'model' => $model,
                'messages' => $messages,
                'response_format' => ['type' => 'json_object'],
            ]);

            // Finish AI request span with token usage
            $usage = $result->usage ? $result->usage->toArray() : [];
            $finishReason = $result->choices[0]->finishReason ?? null;
            finish_ai_request_span($aiSpan, $usage, $finishReason);

            $summaries = json_decode($result->choices[0]->message->content, true);

            // Validate response structure
            $requiredKeys = ['summary_tweet', 'summary_short', 'summary_paragraph', 'key_takeaways', 'tldr', 'emoji', 'tags'];
            foreach ($requiredKeys as $key) {
                if (! isset($summaries[$key])) {
                    throw new Exception("Missing required summary type: {$key}");
                }
            }

            Log::debug('Newsletter: AI summaries generated successfully', [
                'emoji' => $summaries['emoji'] ?? null,
                'tag_count' => count($summaries['tags'] ?? []),
            ]);

            return $summaries;
        } catch (Exception $e) {
            Log::error('Newsletter: AI summary generation failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function createSummaryBlocks(array $summaries): void
    {
        $eventTime = $this->event->time;

        // Block 1: Tweet Summary
        $this->event->createBlock([
            'title' => 'Tweet Summary',
            'block_type' => 'newsletter_summary_tweet',
            'time' => $eventTime,
            'metadata' => [
                'content' => $summaries['summary_tweet'],
                'char_count' => strlen($summaries['summary_tweet']),
                'generated_at' => now()->toIso8601String(),
                'model' => 'gpt-5-nano',
            ],
        ]);

        // Block 2: Short Summary
        $this->event->createBlock([
            'title' => 'Short Summary',
            'block_type' => 'newsletter_summary_short',
            'time' => $eventTime,
            'metadata' => [
                'content' => $summaries['summary_short'],
                'word_count' => str_word_count($summaries['summary_short']),
                'generated_at' => now()->toIso8601String(),
                'model' => 'gpt-5-nano',
            ],
        ]);

        // Block 3: Paragraph Summary
        $this->event->createBlock([
            'title' => 'Paragraph Summary',
            'block_type' => 'newsletter_summary_paragraph',
            'time' => $eventTime,
            'metadata' => [
                'content' => $summaries['summary_paragraph'],
                'word_count' => str_word_count($summaries['summary_paragraph']),
                'generated_at' => now()->toIso8601String(),
                'model' => 'gpt-5-nano',
            ],
        ]);

        // Block 4: Key Takeaways
        $this->event->createBlock([
            'title' => 'Key Takeaways',
            'block_type' => 'newsletter_key_takeaways',
            'time' => $eventTime,
            'metadata' => [
                'content' => $summaries['key_takeaways'],
                'count' => count($summaries['key_takeaways']),
                'generated_at' => now()->toIso8601String(),
                'model' => 'gpt-5-nano',
            ],
        ]);

        // Block 5: TL;DR
        $this->event->createBlock([
            'title' => 'TL;DR',
            'block_type' => 'newsletter_tldr',
            'time' => $eventTime,
            'metadata' => [
                'content' => $summaries['tldr'],
                'word_count' => str_word_count($summaries['tldr']),
                'generated_at' => now()->toIso8601String(),
                'model' => 'gpt-5-nano',
            ],
        ]);

        Log::info('Newsletter: Created 5 summary blocks for event', [
            'event_id' => $this->event->id,
        ]);
    }

    private function attachTags(array $summaries): void
    {
        // Attach emoji tag if present
        if (! empty($summaries['emoji'])) {
            // Attach to EventObject (publication)
            $this->publication->attachTags([$summaries['emoji']], 'spark-emoji');

            // Attach to Event (detach old ones first to override)
            $this->event->detachTags($this->event->tagsWithType('spark-emoji'));
            $this->event->attachTags([$summaries['emoji']], 'spark-emoji');

            Log::debug('Newsletter: Attached emoji tag', [
                'emoji' => $summaries['emoji'],
                'publication_id' => $this->publication->id,
                'event_id' => $this->event->id,
            ]);
        }

        // Attach semantic tags if present
        if (! empty($summaries['tags']) && is_array($summaries['tags'])) {
            // Group tags by type for efficient processing
            $tagsByType = [];
            foreach ($summaries['tags'] as $tagData) {
                if (isset($tagData['tag']) && isset($tagData['tag_type'])) {
                    $tagsByType[$tagData['tag_type']][] = $tagData['tag'];
                }
            }

            // Attach tags by type
            foreach ($tagsByType as $type => $tags) {
                // Attach to EventObject (publication)
                $this->publication->attachTags($tags, $type);

                // Attach to Event (replace existing tags of this type)
                $this->event->detachTags($this->event->tagsWithType($type));
                $this->event->attachTags($tags, $type);

                Log::debug('Newsletter: Attached semantic tags', [
                    'tag_type' => $type,
                    'tags' => $tags,
                    'publication_id' => $this->publication->id,
                    'event_id' => $this->event->id,
                ]);
            }

            Log::info('Newsletter: Attached tags to publication and event', [
                'publication_id' => $this->publication->id,
                'event_id' => $this->event->id,
                'emoji' => $summaries['emoji'] ?? null,
                'tag_count' => count($summaries['tags']),
            ]);
        }
    }
}
