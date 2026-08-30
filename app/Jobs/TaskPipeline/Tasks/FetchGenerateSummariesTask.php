<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Models\Event;
use App\Services\Ai\AiModel;
use App\Services\Ai\Knowledge\SummaryGenerator;
use Exception;
use Illuminate\Support\Facades\Log;

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
        return app(SummaryGenerator::class)->generate($title, $articleText, ['event_id' => $this->model->id]);
    }


    private function createSummaryBlocks(Event $event, $webpage, array $extracted, array $summaries): void
    {
        $model = AiModel::Extraction->model();

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
                'model' => $model,
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
                'model' => $model,
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
                'model' => $model,
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
                'model' => $model,
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
                'model' => $model,
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
