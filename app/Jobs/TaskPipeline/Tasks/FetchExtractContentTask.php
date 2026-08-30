<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Event;
use App\Services\Ai\Knowledge\ContentExtractor;
use Exception;
use Illuminate\Support\Facades\Log;

class FetchExtractContentTask extends BaseTaskJob
{
    protected function execute(): void
    {
        if (! $this->model instanceof Event) {
            throw new Exception('Fetch extract task requires an Event model.');
        }

        $event = $this->model->loadMissing(['target', 'integration', 'blocks']);
        $webpage = $event->target;

        if (! $webpage) {
            throw new Exception('Fetch event does not have a webpage target.');
        }

        $extracted = $this->buildExtractedPayload($event);
        if (trim($extracted['text_content']) === '') {
            throw new Exception('Fetch event has no raw content block text.');
        }

        try {
            $articleText = $this->extractArticleText($extracted['title'], $extracted['text_content']);

            $webpage->update(['content' => $articleText]);

            ProcessTaskPipelineJob::dispatch(
                model: $event->fresh(['target', 'integration', 'blocks']),
                trigger: 'manual',
                taskFilter: ['fetch_generate_summaries'],
            );

            Log::info('Fetch: Article text extracted via TaskPipeline', [
                'event_id' => $event->id,
                'webpage_id' => $webpage->id,
                'word_count' => str_word_count($articleText),
            ]);
        } catch (Exception $e) {
            $metadata = $webpage->metadata ?? [];
            $metadata['last_extraction_error'] = $e->getMessage();
            $metadata['last_extraction_error_at'] = now()->toIso8601String();
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

    private function extractArticleText(string $title, string $content): string
    {
        return app(ContentExtractor::class)->extract(
            'knowledge/extract-article',
            'title',
            $title,
            $content,
            ['event_id' => $this->model->id],
        );
    }
}
