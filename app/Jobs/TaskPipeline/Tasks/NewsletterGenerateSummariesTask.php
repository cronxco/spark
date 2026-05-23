<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Models\Event;
use Exception;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

class NewsletterGenerateSummariesTask extends BaseTaskJob
{
    protected function execute(): void
    {
        if (! $this->model instanceof Event) {
            throw new Exception('Newsletter summary task requires an Event model.');
        }

        $event = $this->model->loadMissing(['target', 'integration']);
        $publication = $event->target?->fresh();
        $articleText = $publication?->content;

        if (! $publication) {
            throw new Exception('Newsletter event does not have a publication target.');
        }

        if (! is_string($articleText) || trim($articleText) === '') {
            throw new Exception('Newsletter event has no extracted content to summarize.');
        }

        try {
            $summaries = $this->generateSummaries(
                $event->event_metadata['email_subject'] ?? 'No Subject',
                $articleText
            );

            $this->createSummaryBlocks($event, $summaries);

            if (! empty($summaries['emoji']) || ! empty($summaries['tags'])) {
                $this->attachTags($event, $publication, $summaries);
            }

            Log::info('Newsletter: Summaries generated via TaskPipeline', [
                'event_id' => $event->id,
                'publication_id' => $publication->id,
            ]);
        } catch (Exception $e) {
            $metadata = $event->event_metadata ?? [];
            $metadata['last_summary_error'] = $e->getMessage();
            $metadata['last_summary_error_at'] = now()->toIso8601String();
            $event->update(['event_metadata' => $metadata]);

            throw $e;
        }
    }

    private function generateSummaries(string $subject, string $articleText): array
    {
        $contentLength = strlen($articleText);
        $maxContentLength = 150000;
        $wasTruncated = $contentLength > $maxContentLength;
        $contentToSend = mb_substr($articleText, 0, $maxContentLength);

        if ($wasTruncated) {
            Log::warning('Newsletter: Article text truncated for summary generation', [
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

        $usage = $result->usage ? $result->usage->toArray() : [];
        $finishReason = $result->choices[0]->finishReason ?? null;
        finish_ai_request_span($aiSpan, $usage, $finishReason);

        $summaries = json_decode($result->choices[0]->message->content, true);
        if (! is_array($summaries)) {
            throw new Exception('Summary response was not valid JSON');
        }

        foreach (['summary_tweet', 'summary_short', 'summary_paragraph', 'key_takeaways', 'tldr', 'emoji', 'tags'] as $key) {
            if (! isset($summaries[$key])) {
                throw new Exception("Missing required summary type: {$key}");
            }
        }

        return $summaries;
    }

    private function createSummaryBlocks(Event $event, array $summaries): void
    {
        $eventTime = $event->time;

        $event->createBlock([
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

        $event->createBlock([
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

        $event->createBlock([
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

        $event->createBlock([
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

        $event->createBlock([
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
    }

    private function attachTags(Event $event, $publication, array $summaries): void
    {
        if (! empty($summaries['emoji'])) {
            $publication->attachTags([$summaries['emoji']], 'spark-emoji');
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
                $publication->attachTags($tags, $type);
                $event->detachTags($event->tagsWithType($type));
                $event->attachTags($tags, $type);
            }
        }
    }
}
