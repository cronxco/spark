<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Models\Event;
use App\Models\EventObject;
use App\Services\Ai\AiModel;
use App\Services\Ai\Knowledge\SummaryGenerator;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FetchGenerateSummariesTask extends BaseTaskJob
{
    private const AI_TAG_TYPES = [
        'spark-emoji',
        'topic-tag',
        'person-tag',
        'organisation-tag',
        'organization-tag',
        'place-tag',
    ];

    protected function execute(): void
    {
        if (! $this->model instanceof Event) {
            throw new Exception('Fetch summary task requires an Event model.');
        }

        $event = $this->model->loadMissing(['target', 'integration', 'blocks']);
        $webpage = $event->target?->fresh();

        if (! $webpage) {
            throw new Exception('Fetch event does not have a webpage target.');
        }

        $contentBlock = $event->blocks->firstWhere('block_type', 'fetch_content');
        $articleText = $contentBlock?->metadata['article_text'] ?? null;

        // Legacy events may not yet have article_text persisted on their raw block.
        $articleText ??= $webpage->content;

        if (! is_string($articleText) || trim($articleText) === '') {
            throw new Exception('Fetch event has no extracted content to summarize.');
        }

        $extracted = $this->buildExtractedPayload($event);

        try {
            $summaries = $this->generateSummaries($extracted['title'], $articleText);

            $this->createSummaryBlocks($event, $summaries);

            $this->attachTags($event, $summaries);

            $event->refresh();
            $event->update([
                'event_metadata' => array_merge($event->event_metadata ?? [], [
                    'enrichment_status' => 'complete',
                    'enriched_content_hash' => $event->event_metadata['content_hash'] ?? null,
                    'enriched_at' => now()->toIso8601String(),
                ]),
            ]);

            $this->withLatestRevision($event, function (EventObject $webpage) use ($event, $extracted): void {
                $metadata = $webpage->metadata ?? [];
                $metadata['author'] = $extracted['author'];
                $metadata['image_url'] = $extracted['image'];
                $metadata['direction'] = $extracted['direction'];
                $metadata['pipeline_status'] = 'complete';
                $metadata['enriched_content_hash'] = $event->event_metadata['content_hash'] ?? null;
                $metadata['extracted_at'] = now()->toIso8601String();
                $webpage->update(['metadata' => $metadata]);
            });

            Log::info('Fetch: Summaries generated via TaskPipeline', [
                'event_id' => $event->id,
                'webpage_id' => $webpage->id,
            ]);
        } catch (Exception $e) {
            $this->withLatestRevision($event, function (EventObject $webpage) use ($e): void {
                $metadata = $webpage->metadata ?? [];
                $metadata['last_summary_error'] = $e->getMessage();
                $metadata['last_summary_error_at'] = now()->toIso8601String();
                $webpage->update(['metadata' => $metadata]);
            });

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
            'title' => $event->target_metadata['title'] ?? $webpage?->title ?? $event->event_metadata['title'] ?? 'Untitled',
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

    private function createSummaryBlocks(Event $event, array $summaries): void
    {
        $model = AiModel::Extraction->model();

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
                'source_content_hash' => $event->event_metadata['content_hash'] ?? null,
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
                'source_content_hash' => $event->event_metadata['content_hash'] ?? null,
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
                'source_content_hash' => $event->event_metadata['content_hash'] ?? null,
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
                'source_content_hash' => $event->event_metadata['content_hash'] ?? null,
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
                'source_content_hash' => $event->event_metadata['content_hash'] ?? null,
            ],
        ]);
    }

    private function attachTags(Event $event, array $summaries): void
    {
        $eventTagSets = $this->tagSets($summaries);
        $this->replaceAiTags($event, $eventTagSets);

        $this->withLatestRevision($event, function (EventObject $webpage) use ($eventTagSets): void {
            $this->replaceAiTags($webpage, $eventTagSets);

            $metadata = $webpage->metadata ?? [];
            if (($metadata['fetch_mode'] ?? 'recurring') === 'once') {
                $metadata['discovery_status'] = 'completed';
                $webpage->update(['metadata' => $metadata]);
            }
        });
    }

    private function tagSets(array $summaries): array
    {
        $tagsByType = [];

        if (! empty($summaries['emoji'])) {
            $tagsByType['spark-emoji'] = [$summaries['emoji']];
        }

        if (! empty($summaries['tags']) && is_array($summaries['tags'])) {
            foreach ($summaries['tags'] as $tagData) {
                if (isset($tagData['tag'], $tagData['tag_type'])) {
                    $tagsByType[$tagData['tag_type']][] = $tagData['tag'];
                }
            }
        }

        return $tagsByType;
    }

    private function replaceAiTags($model, array $tagsByType): void
    {
        foreach (self::AI_TAG_TYPES as $type) {
            $model->detachTags($model->tagsWithType($type));

            if (! empty($tagsByType[$type])) {
                $model->attachTags(array_values(array_unique($tagsByType[$type])), $type);
            }
        }
    }

    private function withLatestRevision(Event $event, callable $callback): bool
    {
        return DB::transaction(function () use ($callback, $event): bool {
            $webpage = EventObject::query()->lockForUpdate()->find($event->target_id);

            if (! $webpage || ($webpage->metadata['latest_event_id'] ?? null) !== $event->id) {
                return false;
            }

            $callback($webpage);

            return true;
        });
    }
}
