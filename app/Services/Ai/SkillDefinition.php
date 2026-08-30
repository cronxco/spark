<?php

namespace App\Services\Ai;

/**
 * A vendored Flint skill: its prompt body plus the manifest that constrains
 * how it may be run.
 */
class SkillDefinition
{
    /**
     * @param  array<int, string>  $allowedTools
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly AiModel $model,
        public readonly array $allowedTools,
        public readonly int $maxToolCalls,
        public readonly int $timeoutSeconds,
        public readonly string $body,
    ) {}
}
