<?php

namespace App\Services\Ai;

/** Kept in memory only; tool schemas must never enter persistence or logs. */
final readonly class SkillContinuation
{
    /** @param array<int, array<string, mixed>> $mcpListTools */
    public function __construct(
        public string $responseId,
        public array $mcpListTools,
    ) {}
}
