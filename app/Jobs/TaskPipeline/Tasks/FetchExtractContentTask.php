<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;
use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Event;
use App\Services\Ai\AiModel;
use Exception;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

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
        $contentLength = strlen($content);
        $maxContentLength = 150000;
        $wasTruncated = $contentLength > $maxContentLength;
        $contentToSend = mb_substr($content, 0, $maxContentLength);

        if ($wasTruncated) {
            Log::warning('Fetch: Content truncated for AI processing', [
                'event_id' => $this->model->id,
                'original_length' => $contentLength,
                'truncated_to' => strlen($contentToSend),
            ]);
        }

        $systemPrompt = <<<'PROMPT'
You are an intelligent content extractor. Given an article title and raw content, extract and return the clean article text formatted in Markdown.

**IMPORTANT**: Your output MUST be formatted in Markdown with appropriate formatting (headings, bold, italic, links, lists, quotes, code blocks, etc.) to enhance readability.

Requirements:
1. Remove navigation, ads, footers, cookie notices, and other non-article content
2. Preserve the complete article text including all paragraphs
3. Format the content using proper Markdown syntax:
   - Use # ## ### for headings
   - Use **bold** and *italic* for emphasis
   - Use > for blockquotes
   - Use - or * for unordered lists, 1. 2. 3. for ordered lists
   - Use [text](url) for links
   - Use `code` for inline code, ``` for code blocks
4. Keep all important content intact
5. Return only the clean article text as Markdown (not JSON)

The output should be the full, clean article text in Markdown format that a reader would want to read.
PROMPT;

        $model = AiModel::Extraction->model();
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            [
                'role' => 'user',
                'content' => json_encode([
                    'title' => $title,
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
