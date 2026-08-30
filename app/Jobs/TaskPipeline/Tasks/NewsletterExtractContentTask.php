<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Event;
use App\Services\Ai\AiModel;
use Exception;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

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
        $contentLength = mb_strlen($content);
        $maxContentLength = 150000;
        $wasTruncated = $contentLength > $maxContentLength;
        $contentToSend = mb_substr($content, 0, $maxContentLength);

        if ($wasTruncated) {
            Log::warning('Newsletter: Content truncated for AI processing', [
                'event_id' => $this->model->id,
                'original_length' => $contentLength,
                'truncated_to' => strlen($contentToSend),
            ]);
        }

        $systemPrompt = <<<'PROMPT'
You are an intelligent newsletter content extractor. Given a newsletter email HTML or text, extract and return the clean article text formatted in Markdown.

**IMPORTANT**: Your output MUST be formatted in Markdown with appropriate formatting (headings, bold, italic, links, lists, quotes, code blocks, etc.) to enhance readability.

Requirements:
1. Remove email headers, footers, unsubscribe links, social media buttons, and other email-specific content
2. Preserve the complete article/newsletter text including all paragraphs
3. Format the content using proper Markdown syntax:
   - Use # ## ### for headings
   - Use **bold** and *italic* for emphasis
   - Use > for blockquotes
   - Use - or * for unordered lists, 1. 2. 3. for ordered lists
   - Use [text](url) for links
   - Use `code` for inline code, ``` for code blocks
4. Keep all important content intact
5. Return only the clean article text as Markdown (not JSON)

The output should be the full, clean newsletter content in Markdown format that a reader would want to read.
PROMPT;

        $model = AiModel::Extraction->model();
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            [
                'role' => 'user',
                'content' => json_encode([
                    'subject' => $subject,
                    'content' => $contentToSend,
                ]),
            ],
        ];
        $aiSpan = start_ai_request_span($model, $messages, []);

        $result = OpenAI::chat()->create([
            'model' => $model,
            'messages' => $messages,
        ]);

        $usage = $result->usage ? $result->usage->toArray() : [];
        $finishReason = $result->choices[0]->finishReason ?? null;
        finish_ai_request_span($aiSpan, $usage, $finishReason);

        $articleText = trim($result->choices[0]->message->content);

        if (empty($articleText)) {
            throw new Exception('Empty article text returned from AI');
        }

        return $articleText;
    }
}
