<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Event;
use App\Services\Ai\Knowledge\ContentExtractor;
use Exception;
use Illuminate\Support\Facades\Log;

class NewsletterExtractContentTask extends BaseTaskJob
{
    protected function execute(): void
    {
        if (! $this->model instanceof Event) {
            throw new Exception('Newsletter extract task requires an Event model.');
        }

        $event = $this->model->loadMissing(['target', 'integration']);
        $publication = $event->target;
        $rawContent = $event->event_metadata['raw_html'] ?? null;

        if (! $publication) {
            throw new Exception('Newsletter event does not have a publication target.');
        }

        if (! is_string($rawContent) || $rawContent === '') {
            throw new Exception('Newsletter event has no raw_html metadata.');
        }

        Log::info('Newsletter: Extracting newsletter content via TaskPipeline', [
            'integration_id' => $event->integration_id,
            'event_id' => $event->id,
            'publication_id' => $publication->id,
        ]);

        try {
            $articleText = $this->extractArticleText(
                $event->event_metadata['email_subject'] ?? 'No Subject',
                $rawContent
            );

            $metadata = $publication->metadata ?? [];
            $metadata['extracted_at'] = now()->toIso8601String();

            $publication->update([
                'content' => $articleText,
                'metadata' => $metadata,
            ]);

            ProcessTaskPipelineJob::dispatch(
                model: $event->fresh(['target', 'integration']),
                trigger: 'manual',
                taskFilter: ['newsletter_generate_summaries'],
            );

            Log::info('Newsletter: Article text extracted via TaskPipeline', [
                'event_id' => $event->id,
                'publication_id' => $publication->id,
                'word_count' => str_word_count($articleText),
            ]);
        } catch (Exception $e) {
            $metadata = $event->event_metadata ?? [];
            $metadata['last_extraction_error'] = $e->getMessage();
            $metadata['last_extraction_error_at'] = now()->toIso8601String();
            $event->update(['event_metadata' => $metadata]);

            throw $e;
        }
    }

    private function extractArticleText(string $subject, string $content): string
    {
        return app(ContentExtractor::class)->extract(
            'knowledge/extract-newsletter',
            'subject',
            $subject,
            $content,
            ['event_id' => $this->model->id],
        );
    }
}
