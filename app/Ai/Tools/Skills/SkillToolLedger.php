<?php

namespace App\Ai\Tools\Skills;

use App\Models\ActionProgress;

/**
 * What the tools of one skill run did, shared by every {@see SkillTool} in
 * the run.
 */
class SkillToolLedger
{
    /** @var array<int, string> */
    public array $called = [];

    /** @var array<int, string> */
    public array $succeeded = [];

    /** @var array<int, string> Tools that failed and were handed back to the model, once per failure. */
    public array $failed = [];

    /** @var array<int, array{tool: string, arguments: mixed}> */
    public array $capturedWrites = [];

    public ?string $eventId = null;

    public function __construct(
        public readonly int $maxToolCalls,
        public readonly bool $dryRun = false,
        public readonly ?ActionProgress $progress = null,
    ) {}

    public function limitReached(): bool
    {
        return count($this->called) >= $this->maxToolCalls;
    }

    /** @param array<string, mixed> $details */
    public function progress(string $step, string $message, int $percentage, array $details = []): void
    {
        $this->progress?->updateProgress($step, $message, $percentage, array_merge($this->progress->details ?? [], $details));
    }
}
