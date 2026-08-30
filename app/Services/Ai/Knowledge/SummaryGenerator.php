<?php

namespace App\Services\Ai\Knowledge;

use App\Services\Ai\AiModel;
use App\Services\Ai\ChatClient;
use App\Services\Ai\PromptRepository;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Produces the seven-part summary payload attached to knowledge-domain events.
 *
 * Fetch and Newsletter used byte-identical prompts for this, so they share one
 * generator and one prompt file.
 */
class SummaryGenerator
{
    /** @var array<int, string> */
    public const REQUIRED_KEYS = [
        'summary_tweet',
        'summary_short',
        'summary_paragraph',
        'key_takeaways',
        'tldr',
        'emoji',
        'tags',
    ];

    public function __construct(
        private PromptRepository $prompts,
        private ChatClient $chat,
    ) {}

    /**
     * @param  array<string, mixed>  $logContext
     * @return array<string, mixed>
     *
     * @throws Exception when the response is unusable or incomplete
     */
    public function generate(string $title, string $articleText, array $logContext = []): array
    {
        $contentToSend = ContentWindow::truncate($articleText, $logContext);

        $summaries = $this->normalise($this->chat->json(AiModel::Extraction, [
            ['role' => 'system', 'content' => $this->prompts->get('knowledge/generate-summaries')],
            [
                'role' => 'user',
                'content' => json_encode([
                    'title' => $title,
                    'article_text' => $contentToSend,
                ]),
            ],
        ], ['context' => $logContext]));

        foreach (self::REQUIRED_KEYS as $key) {
            if (! isset($summaries[$key])) {
                throw new Exception("Missing required summary type: {$key}");
            }
        }

        return $summaries;
    }

    /**
     * Repair a response that omitted the tweet-length summary by trimming a
     * longer one, rather than failing the whole job over the shortest field.
     *
     * @param  array<string, mixed>  $summaries
     * @return array<string, mixed>
     */
    public function normalise(array $summaries): array
    {
        if (isset($summaries['summary_tweet'])) {
            return $summaries;
        }

        $source = $summaries['summary_short'] ?? $summaries['tldr'] ?? null;

        if (is_string($source) && $source !== '') {
            $summaries['summary_tweet'] = mb_substr($source, 0, 280);

            Log::warning('Knowledge: repaired missing summary_tweet from summary response', [
                'source_key' => isset($summaries['summary_short']) ? 'summary_short' : 'tldr',
            ]);
        }

        return $summaries;
    }
}
