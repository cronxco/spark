<?php

namespace App\Jobs\Flint;

use App\Models\Block;
use App\Models\EventObject;
use App\Models\User;
use App\Services\AssistantPromptingService;
use App\Services\FlintBlockCreationService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class GenerateArticlesWaitingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300; // 5 minutes

    public function __construct(
        public User $user,
        public int $maxArticles = 5
    ) {}

    public function handle(
        AssistantPromptingService $prompting,
        FlintBlockCreationService $blockCreation
    ): void {
        Log::info('[Flint] [ARTICLES] Starting articles waiting generation', [
            'user_id' => $this->user->id,
            'max_articles' => $this->maxArticles,
        ]);

        // Get unread one-time bookmarks
        $unreadArticles = $this->getUnreadArticles();

        if ($unreadArticles->isEmpty()) {
            Log::info('[Flint] [ARTICLES] No unread articles found', [
                'user_id' => $this->user->id,
            ]);

            return;
        }

        Log::info('[Flint] [ARTICLES] Found unread articles', [
            'user_id' => $this->user->id,
            'count' => $unreadArticles->count(),
        ]);

        // Generate pitches for articles
        $articlesWithPitches = $this->generatePitches($prompting, $unreadArticles);

        if (empty($articlesWithPitches)) {
            Log::warning('[Flint] [ARTICLES] Failed to generate pitches', [
                'user_id' => $this->user->id,
            ]);

            return;
        }

        // Create the articles waiting block
        $flintEvent = $blockCreation->getOrCreateFlintEvent($this->user);
        $block = $blockCreation->createArticlesWaitingBlock($this->user, [
            'title' => 'Articles Waiting',
            'articles' => $articlesWithPitches,
            'total_unread' => $unreadArticles->count(),
        ], $flintEvent);

        Log::info('[Flint] [ARTICLES] Created articles waiting block', [
            'user_id' => $this->user->id,
            'block_id' => $block->id,
            'articles_count' => count($articlesWithPitches),
        ]);
    }

    /**
     * Get unread one-time bookmarks.
     */
    protected function getUnreadArticles(): Collection
    {
        // Get one-time bookmarks that haven't been marked as read
        return EventObject::where('user_id', $this->user->id)
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->whereRaw("metadata->>'fetch_mode' = 'once'")
            ->whereRaw("(metadata->>'read_at') IS NULL")
            ->whereNotNull('content') // Must have extracted content
            ->whereNull('deleted_at')
            ->orderBy('time', 'desc')
            ->limit($this->maxArticles * 2) // Get extra in case some fail
            ->get();
    }

    /**
     * Generate compelling pitches for articles.
     */
    protected function generatePitches(
        AssistantPromptingService $prompting,
        Collection $articles
    ): array {
        $articlesWithPitches = [];

        foreach ($articles->take($this->maxArticles) as $article) {
            $pitch = $this->generateSinglePitch($prompting, $article);

            if ($pitch) {
                $articlesWithPitches[] = [
                    'id' => $article->id,
                    'title' => $article->title,
                    'url' => $article->url,
                    'domain' => $article->metadata['domain'] ?? parse_url($article->url, PHP_URL_HOST),
                    'saved_at' => $article->time->toIso8601String(),
                    'pitch' => $pitch['pitch'],
                    'reading_time' => $pitch['reading_time'] ?? null,
                    'key_points' => $pitch['key_points'] ?? [],
                ];
            }
        }

        return $articlesWithPitches;
    }

    /**
     * Generate a pitch for a single article.
     */
    protected function generateSinglePitch(
        AssistantPromptingService $prompting,
        EventObject $article
    ): ?array {
        $metadata = $article->metadata ?? [];
        $summary = $metadata['summary_short'] ?? $metadata['summary'] ?? null;
        $keyTakeaways = $metadata['key_takeaways'] ?? [];
        $content = $article->content;

        // If we don't have a summary, use the beginning of content
        if (! $summary && $content) {
            $summary = mb_substr(strip_tags($content), 0, 500);
        }

        if (! $summary) {
            return null;
        }

        $takeawaysText = ! empty($keyTakeaways)
            ? "Key takeaways:\n" . implode("\n", array_map(fn ($t) => "- {$t}", array_slice($keyTakeaways, 0, 3)))
            : '';

        $prompt = <<<PROMPT
You are a personal reading concierge helping someone decide what to read.

**Article:**
Title: {$article->title}
URL: {$article->url}
Summary: {$summary}
{$takeawaysText}

**Your Task:**
Create a compelling 1-2 sentence "pitch" that tells the reader WHY they should read this article TODAY.

Return your response as JSON:

```json
{
  "pitch": "Your compelling pitch here - make them want to read it!",
  "reading_time": "5 min",
  "key_points": ["Point 1", "Point 2"]
}
```

**Guidelines:**
- Be specific about what they'll learn or gain
- Create urgency or relevance without being clickbait
- Focus on value, not just summary
- Keep the pitch to 1-2 sentences max
- Estimate reading time based on content length
- Include 2-3 key points they'll learn
PROMPT;

        try {
            $response = $prompting->generateResponse($prompt, [
                'model' => 'gpt-4.1-mini',
                'user_id' => $this->user->id,
                'context' => [
                    'agent_type' => 'article_pitcher',
                    'article_id' => $article->id,
                ],
            ]);

            return $this->parsePitchFromResponse($response);
        } catch (Exception $e) {
            Log::warning('[Flint] [ARTICLES] Failed to generate pitch', [
                'article_id' => $article->id,
                'error' => $e->getMessage(),
            ]);

            // Fallback to basic pitch
            return [
                'pitch' => $summary ? mb_substr($summary, 0, 150) . '...' : 'Read this saved article.',
                'reading_time' => $this->estimateReadingTime($content),
                'key_points' => array_slice($keyTakeaways, 0, 2),
            ];
        }
    }

    /**
     * Parse pitch from AI response.
     */
    protected function parsePitchFromResponse(string $response): ?array
    {
        // Try direct JSON decode
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && isset($decoded['pitch'])) {
            return $decoded;
        }

        // Try to extract from markdown code block
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $response, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && isset($decoded['pitch'])) {
                return $decoded;
            }
        }

        // Try to find any JSON object
        if (preg_match('/(\{.*?\})/s', $response, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && isset($decoded['pitch'])) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Estimate reading time based on content length.
     */
    protected function estimateReadingTime(?string $content): ?string
    {
        if (! $content) {
            return null;
        }

        $wordCount = str_word_count(strip_tags($content));
        $minutes = max(1, (int) ceil($wordCount / 200)); // 200 words per minute

        return $minutes . ' min';
    }
}
