<?php

namespace App\Jobs\Flint;

use App\Models\Block;
use App\Models\Event;
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

class GenerateNewsBriefingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300; // 5 minutes

    public function __construct(
        public User $user,
        public int $hoursBack = 24
    ) {}

    public function handle(
        AssistantPromptingService $prompting,
        FlintBlockCreationService $blockCreation
    ): void {
        Log::info('[Flint] [NEWS] Starting news briefing generation', [
            'user_id' => $this->user->id,
            'hours_back' => $this->hoursBack,
        ]);

        // Get recent content from recurring fetch sources
        $recentContent = $this->getRecentRecurringContent();

        if ($recentContent->isEmpty()) {
            Log::info('[Flint] [NEWS] No recent content from recurring sources', [
                'user_id' => $this->user->id,
            ]);

            return;
        }

        Log::info('[Flint] [NEWS] Found content from recurring sources', [
            'user_id' => $this->user->id,
            'source_count' => $recentContent->count(),
            'article_count' => $recentContent->sum(fn ($source) => count($source['articles'])),
        ]);

        // Generate synthesized briefing using AI
        $briefing = $this->synthesizeBriefing($prompting, $recentContent);

        if (empty($briefing)) {
            Log::warning('[Flint] [NEWS] Failed to generate briefing', [
                'user_id' => $this->user->id,
            ]);

            return;
        }

        // Create the news briefing block
        $flintEvent = $blockCreation->getOrCreateFlintEvent($this->user);
        $block = $blockCreation->createNewsBriefingBlock($this->user, $briefing, $flintEvent);

        Log::info('[Flint] [NEWS] Created news briefing block', [
            'user_id' => $this->user->id,
            'block_id' => $block->id,
            'sources' => count($briefing['sources'] ?? []),
            'key_stories' => count($briefing['key_stories'] ?? []),
        ]);
    }

    /**
     * Get recent content from recurring fetch sources.
     */
    protected function getRecentRecurringContent(): Collection
    {
        $cutoff = now()->subHours($this->hoursBack);

        // Get recurring source webpages with recent events
        $sources = EventObject::where('user_id', $this->user->id)
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->whereRaw("metadata->>'fetch_mode' = 'recurring'")
            ->whereNull('deleted_at')
            ->get();

        $contentBySource = collect();

        foreach ($sources as $source) {
            // Get recent events (fetch_summary events) for this source
            $recentEvents = Event::forUser($this->user->id)
                ->where('target_id', $source->id)
                ->where('service', 'fetch')
                ->whereIn('action', ['fetch_summary_paragraph', 'fetch_summary_short', 'had_update'])
                ->where('time', '>=', $cutoff)
                ->with('blocks')
                ->orderBy('time', 'desc')
                ->limit(5)
                ->get();

            if ($recentEvents->isEmpty()) {
                continue;
            }

            // Extract summaries from events/blocks
            $articles = $recentEvents->map(function ($event) use ($source) {
                $metadata = $event->event_metadata ?? [];
                $summary = $metadata['summary'] ?? $metadata['content'] ?? null;

                // Try to get summary from blocks
                if (! $summary) {
                    $summaryBlock = $event->blocks->first(fn ($b) => in_array($b->block_type, ['fetch_summary_paragraph', 'fetch_summary_short']));
                    if ($summaryBlock) {
                        $summary = $summaryBlock->content ?? $summaryBlock->metadata['summary'] ?? null;
                    }
                }

                if (! $summary) {
                    return null;
                }

                return [
                    'title' => $metadata['title'] ?? $source->title,
                    'summary' => $summary,
                    'url' => $source->url,
                    'fetched_at' => $event->time->toIso8601String(),
                ];
            })->filter();

            if ($articles->isNotEmpty()) {
                $contentBySource->push([
                    'source_id' => $source->id,
                    'source_name' => $source->title ?? parse_url($source->url, PHP_URL_HOST),
                    'source_url' => $source->url,
                    'articles' => $articles->values()->toArray(),
                ]);
            }
        }

        return $contentBySource;
    }

    /**
     * Synthesize a briefing from multiple sources using AI.
     */
    protected function synthesizeBriefing(
        AssistantPromptingService $prompting,
        Collection $contentBySource
    ): ?array {
        // Build context for the AI
        $sourcesContext = '';
        foreach ($contentBySource as $source) {
            $sourcesContext .= "\n## {$source['source_name']}\n";
            $sourcesContext .= "URL: {$source['source_url']}\n\n";

            foreach (array_slice($source['articles'], 0, 3) as $article) {
                $sourcesContext .= "### {$article['title']}\n";
                $sourcesContext .= "{$article['summary']}\n\n";
            }
        }

        $prompt = <<<PROMPT
You are a personal newspaper editor synthesizing a daily news briefing from multiple sources.

**Content from {$contentBySource->count()} subscribed sources (last {$this->hoursBack} hours):**
{$sourcesContext}

**Your Task:**
Create a synthesized news briefing that:
1. Identifies the 3-5 most important stories across all sources
2. Finds common themes or connections between sources
3. Prioritizes actionable or significant information
4. Creates a cohesive narrative

Return your response as JSON:

```json
{
  "title": "Brief descriptive title for today's briefing",
  "summary": "2-3 sentence executive summary of what's important",
  "key_stories": [
    {
      "headline": "Story headline",
      "summary": "2-3 sentence summary",
      "sources": ["Source Name 1", "Source Name 2"],
      "importance": "high|medium|low",
      "action_needed": "Optional: any action the user should take"
    }
  ],
  "themes": [
    {
      "theme": "Theme name",
      "description": "Brief description of this theme across sources"
    }
  ],
  "sources": ["List of source names included"]
}
```

**Guidelines:**
- Focus on what's new or changed
- Highlight connections between sources
- Be concise - user should get the gist in 60 seconds
- Only include "action_needed" if there's something specific to do
- Maximum 5 key stories, 3 themes
PROMPT;

        try {
            $response = $prompting->generateResponse($prompt, [
                'model' => 'gpt-4.1-mini',
                'user_id' => $this->user->id,
                'context' => [
                    'agent_type' => 'news_synthesizer',
                ],
            ]);

            return $this->parseBriefingFromResponse($response);
        } catch (Exception $e) {
            Log::warning('[Flint] [NEWS] Failed to generate briefing', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Parse briefing from AI response.
     */
    protected function parseBriefingFromResponse(string $response): ?array
    {
        // Try direct JSON decode
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $this->validateBriefing($decoded);
        }

        // Try to extract from markdown code block
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $response, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->validateBriefing($decoded);
            }
        }

        // Try to find any JSON object
        if (preg_match('/(\{.*\})/s', $response, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->validateBriefing($decoded);
            }
        }

        return null;
    }

    /**
     * Validate briefing has required fields.
     */
    protected function validateBriefing(array $briefing): ?array
    {
        if (empty($briefing['title']) || empty($briefing['summary'])) {
            return null;
        }

        return $briefing;
    }
}
