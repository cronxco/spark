<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Models\Event;
use App\Services\Ai\AiModel;
use App\Services\Ai\Knowledge\SummaryGenerator;
use Exception;
use Illuminate\Support\Facades\Log;

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
        return app(SummaryGenerator::class)->generate($subject, $articleText, ['event_id' => $this->model->id]);
    }

    private function createSummaryBlocks(Event $event, array $summaries): void
    {
        $model = AiModel::Extraction->model();

        $eventTime = $event->time;

        $event->createBlock([
            'title' => 'Tweet Summary',
            'block_type' => 'newsletter_summary_tweet',
            'time' => $eventTime,
            'metadata' => [
                'content' => $summaries['summary_tweet'],
                'char_count' => strlen($summaries['summary_tweet']),
                'generated_at' => now()->toIso8601String(),
                'model' => $model,
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
                'model' => $model,
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
                'model' => $model,
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
                'model' => $model,
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
                'model' => $model,
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
