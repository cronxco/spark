<?php

namespace App\Services\Ai;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Tools\ToolNameResolver;
use Sentry\Tracing\Span;

/**
 * Opens a gen_ai.execute_tool span around every tool laravel/ai runs, for any
 * agent, keyed by the SDK's per-call tool invocation id.
 */
class AiToolTracing
{
    /** @var array<string, Span|null> */
    private array $spans = [];

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(InvokingTool::class, function (InvokingTool $event): void {
            $this->spans[$event->toolInvocationId] = start_ai_tool_span(ToolNameResolver::resolve($event->tool));
        });
        $events->listen(ToolInvoked::class, fn (ToolInvoked $event) => $this->finish($event->toolInvocationId, true));
        $events->listen(ToolFailed::class, fn (ToolFailed $event) => $this->finish($event->toolInvocationId, false));
    }

    private function finish(string $toolInvocationId, bool $successful): void
    {
        if (! array_key_exists($toolInvocationId, $this->spans)) {
            return;
        }

        finish_ai_tool_span($this->spans[$toolInvocationId], $successful);
        unset($this->spans[$toolInvocationId]);
    }
}
