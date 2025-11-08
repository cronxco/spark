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

class GenerateSummariesJob implements ShouldQueue
{
    use Dispatchable, EnhancedIdempotency, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes for AI processing

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public function __construct(
        public Integration $integration,
        public Event $event,
        public EventObject $webpage,
        public array $extracted,
        public string $articleText
    ) {}

    public function handle(): void
    {
        Log::info('Fetch: Generating summaries with AI', [
            'integration_id' => $this->integration->id,
            'event_id' => $this->event->id,
            'webpage_id' => $this->webpage->id,
            'url' => $this->webpage->url,
        ]);

        try {
            // Generate summaries using AI
            $summaries = $this->generateSummaries(
                $this->extracted['title'],
                $this->articleText
            );

            // Create summary blocks (Blocks 3-9)
            $this->createSummaryBlocks($summaries);

            // Attach tags to webpage EventObject (only if tags are present)
            if (! empty($summaries['emoji']) || ! empty($summaries['tags'])) {
                $this->attachTags($summaries);
            }

            Log::info('Fetch: Summaries generated successfully', [
                'event_id' => $this->event->id,
                'url' => $this->webpage->url,
            ]);
        } catch (Exception $e) {
            Log::error('Fetch: Summary generation failed', [
                'url' => $this->webpage->url,
                'event_id' => $this->event->id,
                'error' => $e->getMessage(),
            ]);

            // Update webpage metadata with error
            $metadata = $this->webpage->metadata ?? [];
            $metadata['last_summary_error'] = $e->getMessage();
            $metadata['last_summary_error_at'] = now()->toIso8601String();
            $this->webpage->update(['metadata' => $metadata]);

            throw $e;
        }
    }

    public function uniqueId(): string
    {
        return 'generate_summaries_' . $this->integration->id . '_' . $this->event->id;
    }

    private function generateSummaries(string $title, string $articleText): array
    {
        $contentLength = strlen($articleText);
        $maxContentLength = 15000;
        $wasTruncated = $contentLength > $maxContentLength;
        $contentToSend = mb_substr($articleText, 0, $maxContentLength);

        Log::debug('Fetch: Generating AI summaries', [
            'title' => $title,
            'content_length' => $contentLength,
            'truncated' => $wasTruncated,
            'truncated_length' => $wasTruncated ? strlen($contentToSend) : null,
        ]);

        if ($wasTruncated) {
            Log::warning('Fetch: Article text truncated for summary generation', [
                'url' => $this->webpage->url ?? 'unknown',
                'original_length' => $contentLength,
                'truncated_to' => strlen($contentToSend),
                'characters_lost' => $contentLength - strlen($contentToSend),
                'percentage_sent' => round((strlen($contentToSend) / $contentLength) * 100, 1) . '%',
            ]);
        }

        $systemPrompt = <<<'PROMPT'
You are an intelligent content summarizer. Given an article title and clean article text, provide exactly 7 different outputs in JSON.

Requirements:
1. summary_tweet: 280 characters maximum, ultra-concise, engaging
2. summary_short: No more than 40 words, concise overview
3. summary_paragraph: No more than 150 words, detailed overview with key points
4. key_takeaways: Array of 3-5 strings, each a bullet point with actionable insight
5. tldr: Single sentence (max 20 words), absolute minimum summary
6. emoji: Single emoji that best represents the article's theme or content
7. tags: Array of 1-5 semantic tags with types. Only include tags that are clearly relevant and mentioned in the content:
   - "topic-tag" for subjects/themes (e.g., "Machine Learning", "Climate Change")
   - "person-tag" for people mentioned (e.g., "Elon Musk", "Jane Doe")
   - "organisation-tag" for organizations (e.g., "NASA", "Microsoft")
   - "place-tag" for locations (e.g., "New York", "Mars")

Return ONLY valid JSON in this exact format:
{
  "summary_tweet": "280 char version here",
  "summary_short": "40 word version here",
  "summary_paragraph": "150 word version here",
  "key_takeaways": ["point 1", "point 2", "point 3"],
  "tldr": "One sentence version here",
  "emoji": "📰",
  "tags": [
    {"tag": "Artificial Intelligence", "tag_type": "topic-tag"},
    {"tag": "Sam Altman", "tag_type": "person-tag"}
  ]
}
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
                            'article_text' => $contentToSend,
                        ]),
                    ],
                ],
                'response_format' => ['type' => 'json_object'],
            ]);

            $summaries = json_decode($result->choices[0]->message->content, true);

            // Validate response structure
            $requiredKeys = ['summary_tweet', 'summary_short', 'summary_paragraph', 'key_takeaways', 'tldr', 'emoji', 'tags'];
            foreach ($requiredKeys as $key) {
                if (! isset($summaries[$key])) {
                    throw new Exception("Missing required summary type: {$key}");
                }
            }

            Log::debug('Fetch: AI summaries generated successfully', [
                'emoji' => $summaries['emoji'] ?? null,
                'tag_count' => count($summaries['tags'] ?? []),
            ]);

            return $summaries;
        } catch (Exception $e) {
            Log::error('Fetch: AI summary generation failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function createSummaryBlocks(array $summaries): void
    {
        $eventTime = $this->event->time;

        // Block 3: Metadata
        $this->event->createBlock([
            'title' => 'Metadata',
            'block_type' => 'fetch_metadata',
            'time' => $eventTime,
            'metadata' => [
                'author' => $this->extracted['author'],
                'image' => $this->extracted['image'],
                'direction' => $this->extracted['direction'],
                'extracted_at' => now()->toIso8601String(),
            ],
        ]);

        // Block 4: Tweet Summary
        $this->event->createBlock([
            'title' => 'Tweet Summary',
            'block_type' => 'fetch_summary_tweet',
            'time' => $eventTime,
            'metadata' => [
                'summary' => $summaries['summary_tweet'],
                'char_count' => strlen($summaries['summary_tweet']),
                'generated_at' => now()->toIso8601String(),
                'model' => 'gpt-5-nano',
            ],
        ]);

        // Block 5: Short Summary
        $this->event->createBlock([
            'title' => 'Short Summary',
            'block_type' => 'fetch_summary_short',
            'time' => $eventTime,
            'metadata' => [
                'summary' => $summaries['summary_short'],
                'word_count' => str_word_count($summaries['summary_short']),
                'generated_at' => now()->toIso8601String(),
                'model' => 'gpt-5-nano',
            ],
        ]);

        // Block 6: Paragraph Summary
        $this->event->createBlock([
            'title' => 'Paragraph Summary',
            'block_type' => 'fetch_summary_paragraph',
            'time' => $eventTime,
            'metadata' => [
                'summary' => $summaries['summary_paragraph'],
                'word_count' => str_word_count($summaries['summary_paragraph']),
                'generated_at' => now()->toIso8601String(),
                'model' => 'gpt-5-nano',
            ],
        ]);

        // Block 7: Key Takeaways
        $this->event->createBlock([
            'title' => 'Key Takeaways',
            'block_type' => 'fetch_key_takeaways',
            'time' => $eventTime,
            'metadata' => [
                'takeaways' => $summaries['key_takeaways'],
                'count' => count($summaries['key_takeaways']),
                'generated_at' => now()->toIso8601String(),
                'model' => 'gpt-5-nano',
            ],
        ]);

        // Block 8: TL;DR
        $this->event->createBlock([
            'title' => 'TL;DR',
            'block_type' => 'fetch_tldr',
            'time' => $eventTime,
            'metadata' => [
                'summary' => $summaries['tldr'],
                'word_count' => str_word_count($summaries['tldr']),
                'generated_at' => now()->toIso8601String(),
                'model' => 'gpt-5-nano',
            ],
        ]);

        // Block 9: Tags
        $this->event->createBlock([
            'title' => 'Tags',
            'block_type' => 'fetch_tags',
            'time' => $eventTime,
            'metadata' => [
                'emoji' => $summaries['emoji'] ?? null,
                'tags' => $summaries['tags'] ?? [],
                'tag_count' => count($summaries['tags'] ?? []),
                'generated_at' => now()->toIso8601String(),
                'model' => 'gpt-5-nano',
            ],
        ]);

        Log::info('Fetch: Created 7 summary blocks for event', [
            'event_id' => $this->event->id,
        ]);
    }

    private function attachTags(array $summaries): void
    {
        // Attach emoji tag if present
        if (! empty($summaries['emoji'])) {
            $this->webpage->attachTags([$summaries['emoji']], 'spark-emoji');

            Log::debug('Fetch: Attached emoji tag', [
                'emoji' => $summaries['emoji'],
                'webpage_id' => $this->webpage->id,
            ]);
        }

        // Attach semantic tags if present
        if (! empty($summaries['tags']) && is_array($summaries['tags'])) {
            foreach ($summaries['tags'] as $tagData) {
                if (isset($tagData['tag']) && isset($tagData['tag_type'])) {
                    $this->webpage->attachTags([$tagData['tag']], $tagData['tag_type']);

                    Log::debug('Fetch: Attached semantic tag', [
                        'tag' => $tagData['tag'],
                        'tag_type' => $tagData['tag_type'],
                        'webpage_id' => $this->webpage->id,
                    ]);
                }
            }

            Log::info('Fetch: Attached tags to webpage', [
                'webpage_id' => $this->webpage->id,
                'emoji' => $summaries['emoji'] ?? null,
                'tag_count' => count($summaries['tags']),
            ]);
        }
    }
}
