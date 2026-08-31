<?php

namespace App\Services\Ai\Knowledge;

use App\Services\Ai\AiModel;
use App\Services\Ai\ChatClient;
use App\Services\Ai\PromptRepository;
use Exception;

/**
 * Turns raw fetched HTML or newsletter email text into clean Markdown.
 *
 * Shared by the Fetch and Newsletter task pipelines and by the linkable-URL
 * path, which has no Event and so cannot run through the task pipeline.
 */
class ContentExtractor
{
    public function __construct(
        private PromptRepository $prompts,
        private ChatClient $chat,
    ) {}

    /**
     * @param  string  $promptKey  Prompt under resources/ai/prompts
     * @param  string  $titleField  Key the payload carries the title under
     * @param  array<string, mixed>  $logContext
     *
     * @throws Exception when the model returns nothing usable
     */
    public function extract(
        string $promptKey,
        string $titleField,
        string $title,
        string $content,
        array $logContext = []
    ): string {
        $contentToSend = ContentWindow::truncate($content, $logContext);

        $articleText = trim($this->chat->text(AiModel::Extraction, [
            ['role' => 'system', 'content' => $this->prompts->get($promptKey)],
            [
                'role' => 'user',
                'content' => json_encode([
                    $titleField => $title,
                    'content' => $contentToSend,
                ]),
            ],
        ], ['context' => ['operation' => 'knowledge_extract'] + $logContext]));

        if ($articleText === '') {
            throw new Exception('Empty article text returned from AI');
        }

        return $articleText;
    }
}
