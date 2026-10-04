<?php

namespace App\Services\Ai;

final readonly class AiTokenUsage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cachedTokens = 0,
        public int $reasoningTokens = 0,
    ) {}

    /** @param array<string, mixed> $usage */
    public static function fromArray(array $usage): self
    {
        $inputDetails = $usage['input_tokens_details'] ?? $usage['prompt_tokens_details'] ?? [];
        $outputDetails = $usage['output_tokens_details'] ?? $usage['completion_tokens_details'] ?? [];

        return new self(
            inputTokens: (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0),
            cachedTokens: (int) ($inputDetails['cached_tokens'] ?? 0),
            reasoningTokens: (int) ($outputDetails['reasoning_tokens'] ?? 0),
        );
    }

    public function totalTokens(): int
    {
        return max(0, $this->inputTokens) + max(0, $this->outputTokens);
    }
}
