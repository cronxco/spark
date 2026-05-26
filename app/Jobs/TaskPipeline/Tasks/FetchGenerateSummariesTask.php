<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Models\Event;
use Exception;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

class FetchGenerateSummariesTask extends BaseTaskJob
{
    protected function execute(): void
    {
        if (! $this->model instanceof Event) {
            throw new Exception('Fetch summary task requires an Event model.');
        }

        $event = $this->model->loadMissing(['target', 'integration', 'blocks']);
        $webpage = $event->target?->fresh();
        $articleText = $webpage?->content;

        if (! $webpage) {
            throw new Exception('Fetch event does not have a webpage target.');
        }

        if (! is_string($articleText) || trim($articleText) === '') {
            throw new Exception('Fetch event has no extracted content to summarize.');
        }

        $extracted = $this->buildExtractedPayload($event);

        try {
            $summaries = $this->generateSummaries($extracted['title'], $articleText);

            $this->createSummaryBlocks($event, $webpage, $extracted, $summaries);

            if (! empty($summaries['emoji']) || ! empty($summaries['tags'])) {
                $this->attachTags($event, $webpage, $summaries);
            }

            Log::info('Fetch: Summaries generated via TaskPipeline', [
                'event_id' => $event->id,
                'webpage_id' => $webpage->id,
            ]);
        } catch (Exception $e) {
            $metadata = $webpage->metadata ?? [];
            $metadata['last_summary_error'] = $e->getMessage();
            $metadata['last_summary_error_at'] = now()->toIso8601String();
            $webpage->update(['metadata' => $metadata]);

            throw $e;
        }
    }

    /**
     * @return array{title: string, content: string, text_content: string, excerpt: string, author: ?string, image: ?string, direction: string}
     */
    private function buildExtractedPayload(Event $event): array
    {
        $webpage = $event->target;
        $contentBlock = $event->blocks->firstWhere('block_type', 'fetch_content');
        $blockMetadata = $contentBlock?->metadata ?? [];
        $webpageMetadata = $webpage?->metadata ?? [];

        return [
            'title' => $webpage?->title ?: ($event->event_metadata['title'] ?? 'Untitled'),
            'content' => (string) ($blockMetadata['html'] ?? $webpage?->content ?? ''),
            'text_content' => (string) ($blockMetadata['text'] ?? ''),
            'excerpt' => (string) ($blockMetadata['excerpt'] ?? $webpage?->content ?? ''),
            'author' => $webpageMetadata['author'] ?? null,
            'image' => $webpageMetadata['image_url'] ?? $webpage?->media_url,
            'direction' => $webpageMetadata['direction'] ?? 'ltr',
        ];
    }

    private function generateSummaries(string $title, string $articleText): array
    {
        $contentLength = strlen($articleText);
        $maxContentLength = 150000;
        $wasTruncated = $contentLength > $maxContentLength;
        $contentToSend = mb_substr($articleText, 0, $maxContentLength);

        if ($wasTruncated) {
            Log::warning('Fetch: Article text truncated for summary generation', [
                'event_id' => $this->model->id,
                'original_length' => $contentLength,
                'truncated_to' => strlen($contentToSend),
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

        $model = 'gpt-5-nano';
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            [
                'role' => 'user',
                'content' => json_encode([
                    'title' => $title,
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

        $usage = $result->usage ? $result->usage->toArray() : [];
        $finishReason = $result->choices[0]->finishReason ?? null;
        finish_ai_request_span($aiSpan, $usage, $finishReason);

        $summaries = json_decode($result->choices[0]->message->content, true);
        if (! is_array($summaries)) {
            throw new Exception('Summary response was not valid JSON');
        }

        $summaries = $this->normaliseSummaries($summaries);

        foreach (['summary_tweet', 'summary_short', 'summary_paragraph', 'key_takeaways', 'tldr', 'emoji', 'tags'] as $key) {
            if (! isset($summaries[$key])) {
                throw new Exception("Missing required summary type: {$key}");
            }
        }

        return $summaries;
    }

    private function normaliseSummaries(array $summaries): array
    {
        if (! isset($summaries['summary_tweet'])) {
            $source = $summaries['summary_short'] ?? $summaries['tldr'] ?? null;

            if (is_string($source) && $source !== '') {
                $summaries['summary_tweet'] = mb_substr($source, 0, 280);

                Log::warning('Fetch: Repaired missing summary_tweet from summary response', [
                    'source_key' => isset($summaries['summary_short']) ? 'summary_short' : 'tldr',
                ]);
            }
        }

        return $summaries;
    }

    private function createSummaryBlocks(Event $event, $webpage, array $extracted, array $summaries): void
    {
        $webpageMetadata = $webpage->metadata ?? [];
        $webpageMetadata['author'] = $extracted['author'];
        $webpageMetadata['image_url'] = $extracted['image'];
        $webpageMetadata['direction'] = $extracted['direction'];
        $webpageMetadata['extracted_at'] = now()->toIso8601String();
        $webpage->update(['metadata' => $webpageMetadata]);

        $eventTime = $event->time;
        $tweetContent = is_array($summaries['summary_tweet']) ? json_encode($summaries['summary_tweet']) : $summaries['summary_tweet'];

        $event->createBlock([
            'title' => 'Tweet Summary',
            'block_type' => 'fetch_summary_tweet',
            'time' => $eventTime,
            'metadata' => [
                'content' => $tweetContent,
                'char_count' => strlen($tweetContent),
                'generated_at' => now()->toIso8601String(),
                'model' => 'gpt-5-nano',
            ],
        ]);

        $event->createBlock([
            'title' => 'Short Summary',
            'block_type' => 'fetch_summary_short',
            'time' => $eventTime,
            'metadata' => [
                'content' => $summaries['summary_short'],
                'word_count' => str_word_count($summaries['summary_short']),
                'generated_at' => now()->toIso8601String(),
                'model' => 'gpt-5-nano',
            ],
        ]);

        $event->createBlock([
            'title' => 'Paragraph Summary',
            'block_type' => 'fetch_summary_paragraph',
            'time' => $eventTime,
            'metadata' => [
                'content' => $summaries['summary_paragraph'],
                'word_count' => str_word_count($summaries['summary_paragraph']),
                'generated_at' => now()->toIso8601String(),
                'model' => 'gpt-5-nano',
            ],
        ]);

        $event->createBlock([
            'title' => 'Key Takeaways',
            'block_type' => 'fetch_key_takeaways',
            'time' => $eventTime,
            'metadata' => [
                'content' => $summaries['key_takeaways'],
                'count' => count($summaries['key_takeaways']),
                'generated_at' => now()->toIso8601String(),
                'model' => 'gpt-5-nano',
            ],
        ]);

        $event->createBlock([
            'title' => 'TL;DR',
            'block_type' => 'fetch_tldr',
            'time' => $eventTime,
            'metadata' => [
                'content' => $summaries['tldr'],
                'word_count' => str_word_count($summaries['tldr']),
                'generated_at' => now()->toIso8601String(),
                'model' => 'gpt-5-nano',
            ],
        ]);
    }

    private function attachTags(Event $event, $webpage, array $summaries): void
    {
        if (! empty($summaries['emoji'])) {
            $webpage->attachTags([$summaries['emoji']], 'spark-emoji');
            $event->detachTags($event->tagsWithType('spark-emoji'));
            $event->attachTags([$summaries['emoji']], 'spark-emoji');
        }

        if (! empty($summaries['tags']) && is_array($summaries['tags'])) {
            $tagsByType = [];
            foreach ($summaries['tags'] as $tagData) {
                if (isset($tagData['tag'], $tagData['tag_type'])) {
                    $tagsByType[$tagData['tag_type']][] = $tagData['tag'];
                }
            }

            foreach ($tagsByType as $type => $tags) {
                $webpage->attachTags($tags, $type);
                $event->detachTags($event->tagsWithType($type));
                $event->attachTags($tags, $type);
            }
        }

        $metadata = $webpage->metadata ?? [];
        if (($metadata['fetch_mode'] ?? 'recurring') === 'once') {
            $metadata['discovery_status'] = 'completed';
            $webpage->update(['metadata' => $metadata]);
        }
    }
}
