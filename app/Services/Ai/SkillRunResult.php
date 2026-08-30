<?php

namespace App\Services\Ai;

/**
 * What a skill run produced, for logging and TaskExecution metadata.
 *
 * Deliberately carries no part of the request: the MCP server URL embeds a
 * bearer token, so nothing derived from it is kept here.
 */
class SkillRunResult
{
    /**
     * @param  array<int, string>  $toolsCalled
     */
    public function __construct(
        public readonly string $skill,
        public readonly string $text,
        public readonly array $toolsCalled,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'skill' => $this->skill,
            'tool_calls' => count($this->toolsCalled),
            'tools_called' => array_values(array_unique($this->toolsCalled)),
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
        ];
    }
}
