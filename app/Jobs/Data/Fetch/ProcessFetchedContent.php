<?php

namespace App\Jobs\Data\Fetch;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Notifications\FetchContentChanged;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

class ProcessFetchedContent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [60, 300, 900];

    public function __construct(
        public Integration $integration,
        public EventObject $webpage,
        public array $extracted,
        public string $contentHash
    ) {}

    public function handle(): void
    {
        Log::info('Fetch: Processing fetched content', [
            'integration_id' => $this->integration->id,
            'webpage_id' => $this->webpage->id,
            'url' => $this->webpage->url,
        ]);

        $metadata = $this->webpage->metadata ?? [];
        $previousHash = $metadata['content_hash'] ?? null;

        // Check if content has changed
        if ($previousHash && $previousHash === $this->contentHash) {
            // Content unchanged - just update last_checked_at
            Log::info('Fetch: Content unchanged, skipping processing', [
                'url' => $this->webpage->url,
                'content_hash' => substr($this->contentHash, 0, 8),
            ]);

            $metadata['last_checked_at'] = now()->toIso8601String();
            $metadata['fetch_count'] = ($metadata['fetch_count'] ?? 0) + 1;
            $this->webpage->update(['metadata' => $metadata]);

            return;
        }

        // Content is new or changed - process it
        Log::info('Fetch: Content changed, generating summaries', [
            'url' => $this->webpage->url,
            'previous_hash' => $previousHash ? substr($previousHash, 0, 8) : 'none',
            'new_hash' => substr($this->contentHash, 0, 8),
        ]);

        try {
            // Generate AI summaries
            $summaries = $this->generateSummaries(
                $this->extracted['title'],
                $this->extracted['text_content']
            );

            // Create or find actor (fetch_user)
            $actorObject = EventObject::firstOrCreate(
                [
                    'user_id' => $this->integration->user_id,
                    'integration_id' => $this->integration->id,
                    'concept' => 'user',
                    'type' => 'fetch_user',
                ],
                [
                    'title' => 'Fetch',
                    'time' => now(),
                    'metadata' => ['service' => 'fetch'],
                ]
            );

            // Create or update today's Event
            $sourceId = 'fetch_' . $this->webpage->id . '_' . now()->format('Y-m-d');
            $action = $previousHash ? 'updated' : 'fetched';

            $event = Event::updateOrCreate(
                [
                    'source_id' => $sourceId,
                    'integration_id' => $this->integration->id,
                ],
                [
                    'user_id' => $this->integration->user_id,
                    'service' => 'fetch',
                    'domain' => 'knowledge',
                    'action' => $action,
                    'time' => now(),
                    'actor_id' => $actorObject->id,
                    'target_id' => $this->webpage->id,
                    'event_metadata' => [
                        'url' => $this->webpage->url,
                        'fetch_time' => now()->toIso8601String(),
                        'content_hash' => $this->contentHash,
                        'content_changed' => true,
                        'previous_hash' => $previousHash,
                        'extraction_success' => true,
                    ],
                ]
            );

            Log::info('Fetch: Event created/updated', [
                'event_id' => $event->id,
                'action' => $action,
            ]);

            // Create/update blocks
            $this->createBlocks($event, $summaries);

            // Update webpage EventObject
            $this->webpage->update([
                'title' => $this->extracted['title'],
                'content' => $this->extracted['excerpt'],
                'media_url' => $this->extracted['image'],
                'metadata' => array_merge($metadata, [
                    'last_checked_at' => now()->toIso8601String(),
                    'last_changed_at' => now()->toIso8601String(),
                    'content_hash' => $this->contentHash,
                    'previous_hash' => $previousHash,
                    'fetch_count' => ($metadata['fetch_count'] ?? 0) + 1,
                    'last_error' => null, // Clear any previous errors
                ]),
            ]);

            // Send notification for content change (only if content was previously fetched)
            if ($previousHash) {
                $this->integration->user->notify(
                    new FetchContentChanged($this->webpage, $previousHash, $this->contentHash)
                );

                Log::info('Fetch: Content change notification sent', [
                    'url' => $this->webpage->url,
                    'user_id' => $this->integration->user_id,
                ]);
            }

            Log::info('Fetch: Processing completed successfully', [
                'url' => $this->webpage->url,
            ]);
        } catch (Exception $e) {
            Log::error('Fetch: Processing failed', [
                'url' => $this->webpage->url,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function generateSummaries(string $title, string $content): array
    {
        Log::debug('Fetch: Generating AI summaries', [
            'title' => $title,
            'content_length' => strlen($content),
        ]);

        $systemPrompt = <<<'PROMPT'
You are an intelligent content summarizer. Given an article title and content, provide exactly 5 different summary formats in JSON.

Requirements:
1. summary_tweet: Exactly 280 characters maximum, ultra-concise, engaging
2. summary_short: Exactly 40 words, concise overview
3. summary_paragraph: Exactly 150 words, detailed overview with key points
4. key_takeaways: Array of 3-5 strings, each a bullet point with actionable insight
5. tldr: Single sentence (max 20 words), absolute minimum summary

Return ONLY valid JSON in this exact format:
{
  "summary_tweet": "280 char version here",
  "summary_short": "40 word version here",
  "summary_paragraph": "150 word version here",
  "key_takeaways": ["point 1", "point 2", "point 3"],
  "tldr": "One sentence version here"
}
PROMPT;

        try {
            // TODO: Update to gpt-5-mini when available
            $result = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemPrompt,
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode([
                            'title' => $title,
                            'content' => mb_substr($content, 0, 10000), // Limit to avoid token issues
                        ]),
                    ],
                ],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.7,
            ]);

            $summaries = json_decode($result->choices[0]->message->content, true);

            // Validate response structure
            $requiredKeys = ['summary_tweet', 'summary_short', 'summary_paragraph', 'key_takeaways', 'tldr'];
            foreach ($requiredKeys as $key) {
                if (! isset($summaries[$key])) {
                    throw new Exception("Missing required summary type: {$key}");
                }
            }

            Log::debug('Fetch: AI summaries generated successfully');

            return $summaries;
        } catch (Exception $e) {
            Log::error('Fetch: AI summary generation failed', [
                'error' => $e->getMessage(),
            ]);

            // Return fallback summaries
            return [
                'summary_tweet' => mb_substr($content, 0, 280),
                'summary_short' => implode(' ', array_slice(str_word_count($content, 1), 0, 40)),
                'summary_paragraph' => implode(' ', array_slice(str_word_count($content, 1), 0, 150)),
                'key_takeaways' => ['Summary generation failed'],
                'tldr' => mb_substr($content, 0, 100),
            ];
        }
    }

    private function createBlocks(Event $event, array $summaries): void
    {
        $eventTime = $event->time;

        // Block 1: Raw Content
        $event->createBlock([
            'title' => 'Raw Content',
            'block_type' => 'fetch_content',
            'time' => $eventTime,
            'metadata' => [
                'html' => $this->extracted['content'],
                'text' => $this->extracted['text_content'],
                'excerpt' => $this->extracted['excerpt'],
            ],
        ]);

        // Block 2: Metadata
        $event->createBlock([
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

        // Block 3: Tweet Summary
        $event->createBlock([
            'title' => 'Tweet Summary',
            'block_type' => 'fetch_summary_tweet',
            'time' => $eventTime,
            'metadata' => [
                'summary' => $summaries['summary_tweet'],
                'char_count' => strlen($summaries['summary_tweet']),
                'generated_at' => now()->toIso8601String(),
                'model' => 'gpt-4o-mini',
            ],
        ]);

        // Block 4: Short Summary
        $event->createBlock([
            'title' => 'Short Summary',
            'block_type' => 'fetch_summary_short',
            'time' => $eventTime,
            'metadata' => [
                'summary' => $summaries['summary_short'],
                'word_count' => str_word_count($summaries['summary_short']),
                'generated_at' => now()->toIso8601String(),
                'model' => 'gpt-4o-mini',
            ],
        ]);

        // Block 5: Paragraph Summary
        $event->createBlock([
            'title' => 'Paragraph Summary',
            'block_type' => 'fetch_summary_paragraph',
            'time' => $eventTime,
            'metadata' => [
                'summary' => $summaries['summary_paragraph'],
                'word_count' => str_word_count($summaries['summary_paragraph']),
                'generated_at' => now()->toIso8601String(),
                'model' => 'gpt-4o-mini',
            ],
        ]);

        // Block 6: Key Takeaways
        $event->createBlock([
            'title' => 'Key Takeaways',
            'block_type' => 'fetch_key_takeaways',
            'time' => $eventTime,
            'metadata' => [
                'takeaways' => $summaries['key_takeaways'],
                'count' => count($summaries['key_takeaways']),
                'generated_at' => now()->toIso8601String(),
                'model' => 'gpt-4o-mini',
            ],
        ]);

        // Block 7: TL;DR
        $event->createBlock([
            'title' => 'TL;DR',
            'block_type' => 'fetch_tldr',
            'time' => $eventTime,
            'metadata' => [
                'summary' => $summaries['tldr'],
                'word_count' => str_word_count($summaries['tldr']),
                'generated_at' => now()->toIso8601String(),
                'model' => 'gpt-4o-mini',
            ],
        ]);

        Log::info('Fetch: Created 7 blocks for event', [
            'event_id' => $event->id,
        ]);
    }
}
