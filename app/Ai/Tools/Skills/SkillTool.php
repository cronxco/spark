<?php

namespace App\Ai\Tools\Skills;

use App\Services\Ai\SkillRunner;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;

/**
 * One tool a Flint skill may call, under its manifest name
 * (`namespace__tool`).
 *
 * Wraps the MCP tool that does the work, whether it runs in-process (Spark's
 * own tools) or on a remote MCP server, and records every call in the run's
 * {@see SkillToolLedger}. In a dry run, a write is recorded and declined
 * instead of run. A failed call is logged and handled by its
 * {@see ToolFailurePolicy}: an optional source that did not answer should not
 * cost the whole digest, and a rejected required write should get a chance to
 * be corrected.
 */
class SkillTool implements Tool
{
    /** How many times a required tool's failure goes back to the model before the run ends. */
    public const MAX_RETRIES = 2;

    public function __construct(
        private string $name,
        private Tool $tool,
        private SkillToolLedger $ledger,
        private ToolFailurePolicy $onFailure = ToolFailurePolicy::EndRun,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return (string) $this->tool->description();
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->tool->schema($schema);
    }

    public function handle(Request $request): string
    {
        if ($this->ledger->limitReached()) {
            return 'Tool call limit reached for this run. Finish with what you already have.';
        }

        $this->ledger->called[] = $this->name;
        $this->ledger->progress('tool_starting', "Running {$this->name}", 40, ['tool' => $this->name]);

        if ($this->ledger->dryRun && $this->isWrite($request->all())) {
            $this->ledger->capturedWrites[] = ['tool' => $this->name, 'arguments' => $request->all()];

            return 'Declined: this is a dry run, so nothing is written. Carry on as if the call had succeeded.';
        }

        $output = (string) $this->tool->handle($request);

        if (SkillRunner::isApplicationError($output)) {
            $error = Str::limit(redact_sensitive_urls($output), 500);
            Log::warning('Flint skill tool failed', ['tool' => $this->name, 'error' => $error]);

            $retries = count(array_keys($this->ledger->failed, $this->name, true));
            if ($this->onFailure === ToolFailurePolicy::EndRun
                || ($this->onFailure === ToolFailurePolicy::Retry && $retries >= self::MAX_RETRIES)) {
                throw new RuntimeException("Tool {$this->name} failed: {$error}");
            }

            $this->ledger->failed[] = $this->name;
            $this->ledger->progress('tool_failed', "{$this->name} failed", 70, ['tool' => $this->name]);

            return $this->onFailure === ToolFailurePolicy::Retry
                ? "{$this->name} failed: {$error}\nCorrect the call and make it again."
                : "{$this->name} failed: {$error}\nLeave out anything that depended on it and carry on.";
        }

        $this->ledger->succeeded[] = $this->name;
        if ($this->name === 'spark__create-flint-digest') {
            $this->ledger->eventId = SkillRunner::eventIdFromOutput($output) ?? $this->ledger->eventId;
        }
        $this->ledger->progress('tool_completed', "Completed {$this->name}", 70, ['tool' => $this->name]);

        return $output;
    }

    /** @param array<string, mixed> $arguments */
    private function isWrite(array $arguments): bool
    {
        if (! in_array($this->name, SkillRunner::WRITE_TOOLS, true)) {
            return false;
        }

        return ! ($this->name === 'spark__manage-flint-topic'
            && in_array($arguments['operation'] ?? null, SkillRunner::READ_ONLY_TOPIC_OPERATIONS, true));
    }
}
