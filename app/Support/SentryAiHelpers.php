<?php

use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;

if (! function_exists('start_ai_request_span')) {
    /**
     * Start a Sentry AI request span for LLM API calls
     *
     * @param  string  $model  The model name (e.g., 'gpt-5.1', 'claude-3-sonnet')
     * @param  array  $messages  Array of message objects with 'role' and 'content' keys
     * @param  array  $config  Additional configuration (temperature, max_tokens, etc.)
     */
    function start_ai_request_span(string $model, array $messages, array $config = []): ?\Sentry\Tracing\Span
    {
        $span = SentrySdk::getCurrentHub()->getSpan();
        if (! $span) {
            return null;
        }

        $childSpan = $span->startChild(new SpanContext);
        $childSpan->setOp('gen_ai.request');
        $childSpan->setDescription("AI Request: {$model}");

        // Set required attributes
        $childSpan->setData([
            'gen_ai.request.model' => $model,
            'gen_ai.system' => explode('/', $model)[0] ?? 'openai', // Extract provider from model name
        ]);

        // Add message history (serialize as JSON strings per Sentry requirements)
        $serializedMessages = array_map(function ($msg) {
            return json_encode([
                'role' => $msg['role'] ?? 'user',
                'content' => $msg['content'] ?? '',
            ]);
        }, $messages);

        $childSpan->setData([
            'gen_ai.request.messages' => $serializedMessages,
        ]);

        // Add configuration parameters
        if (isset($config['temperature'])) {
            $childSpan->setData(['gen_ai.request.temperature' => $config['temperature']]);
        }
        if (isset($config['max_completion_tokens'])) {
            $childSpan->setData(['gen_ai.request.max_tokens' => $config['max_completion_tokens']]);
        }
        if (isset($config['top_p'])) {
            $childSpan->setData(['gen_ai.request.top_p' => $config['top_p']]);
        }

        return $childSpan;
    }
}

if (! function_exists('finish_ai_request_span')) {
    /**
     * Finish an AI request span with token usage tracking
     *
     * @param  \Sentry\Tracing\Span|null  $span  The span to finish
     * @param  array  $usage  Token usage data (input_tokens, output_tokens, total_tokens)
     * @param  string|null  $finishReason  The finish reason (stop, length, content_filter, etc.)
     */
    function finish_ai_request_span(?\Sentry\Tracing\Span $span, array $usage = [], ?string $finishReason = null): void
    {
        if (! $span) {
            return;
        }

        // Track token usage
        if (! empty($usage)) {
            $spanData = [];

            if (isset($usage['prompt_tokens']) || isset($usage['input_tokens'])) {
                $spanData['gen_ai.usage.input_tokens'] = $usage['prompt_tokens'] ?? $usage['input_tokens'];
            }
            if (isset($usage['completion_tokens']) || isset($usage['output_tokens'])) {
                $spanData['gen_ai.usage.output_tokens'] = $usage['completion_tokens'] ?? $usage['output_tokens'];
            }
            if (isset($usage['total_tokens'])) {
                $spanData['gen_ai.usage.total_tokens'] = $usage['total_tokens'];
            }

            // Track cached tokens if available (Claude extended protocol)
            if (isset($usage['cache_read_input_tokens'])) {
                $spanData['gen_ai.usage.cached_tokens'] = $usage['cache_read_input_tokens'];
            }

            // Track reasoning tokens if available (o1 models)
            if (isset($usage['completion_tokens_details']['reasoning_tokens'])) {
                $spanData['gen_ai.usage.reasoning_tokens'] = $usage['completion_tokens_details']['reasoning_tokens'];
            }

            $span->setData($spanData);
        }

        // Track finish reason
        if ($finishReason) {
            $span->setData(['gen_ai.response.finish_reason' => $finishReason]);
        }

        $span->finish();
    }
}

if (! function_exists('start_ai_agent_span')) {
    /**
     * Start a Sentry AI agent invocation span
     *
     * @param  string  $agentName  The name/type of the agent (e.g., 'health_domain_agent', 'cross_domain_synthesizer')
     * @param  array  $input  Input parameters or context for the agent
     */
    function start_ai_agent_span(string $agentName, array $input = []): ?\Sentry\Tracing\Span
    {
        $span = SentrySdk::getCurrentHub()->getSpan();
        if (! $span) {
            return null;
        }

        $childSpan = $span->startChild(new SpanContext);
        $childSpan->setOp('gen_ai.invoke_agent');
        $childSpan->setDescription("Agent: {$agentName}");

        $childSpan->setData([
            'gen_ai.agent.name' => $agentName,
        ]);

        // Add input context (sanitized)
        if (! empty($input)) {
            $sanitizedInput = array_map(function ($value) {
                if (is_string($value) && strlen($value) > 200) {
                    return substr($value, 0, 200) . '... [truncated]';
                }

                return $value;
            }, $input);

            $childSpan->setData(['gen_ai.agent.input' => json_encode($sanitizedInput)]);
        }

        return $childSpan;
    }
}

if (! function_exists('finish_ai_agent_span')) {
    /**
     * Finish an AI agent invocation span with output tracking
     *
     * @param  \Sentry\Tracing\Span|null  $span  The span to finish
     * @param  array  $output  Output or results from the agent
     */
    function finish_ai_agent_span(?\Sentry\Tracing\Span $span, array $output = []): void
    {
        if (! $span) {
            return;
        }

        // Track output summary (sanitized and truncated)
        if (! empty($output)) {
            $outputSummary = [];

            // Common agent output fields to track
            if (isset($output['insights_count'])) {
                $outputSummary['insights_count'] = $output['insights_count'];
            }
            if (isset($output['suggestions_count'])) {
                $outputSummary['suggestions_count'] = $output['suggestions_count'];
            }
            if (isset($output['confidence'])) {
                $outputSummary['confidence'] = $output['confidence'];
            }
            if (isset($output['blocks_created'])) {
                $outputSummary['blocks_created'] = $output['blocks_created'];
            }

            // Fallback: serialize output (truncated)
            if (empty($outputSummary)) {
                $serialized = json_encode($output);
                if (strlen($serialized) > 500) {
                    $serialized = substr($serialized, 0, 500) . '... [truncated]';
                }
                $outputSummary['output'] = $serialized;
            }

            $span->setData(['gen_ai.agent.output' => json_encode($outputSummary)]);
        }

        $span->finish();
    }
}

if (! function_exists('start_ai_tool_span')) {
    /**
     * Start a Sentry AI tool execution span
     *
     * @param  string  $toolName  The name of the tool being executed (e.g., 'create_block', 'store_memory')
     * @param  array  $parameters  Tool input parameters
     */
    function start_ai_tool_span(string $toolName, array $parameters = []): ?\Sentry\Tracing\Span
    {
        $span = SentrySdk::getCurrentHub()->getSpan();
        if (! $span) {
            return null;
        }

        $childSpan = $span->startChild(new SpanContext);
        $childSpan->setOp('gen_ai.execute_tool');
        $childSpan->setDescription("Tool: {$toolName}");

        $childSpan->setData([
            'gen_ai.tool.name' => $toolName,
        ]);

        // Add parameters (sanitized)
        if (! empty($parameters)) {
            $sanitizedParams = array_map(function ($value) {
                if (is_string($value) && strlen($value) > 100) {
                    return substr($value, 0, 100) . '... [truncated]';
                }

                return $value;
            }, $parameters);

            $childSpan->setData(['gen_ai.tool.parameters' => json_encode($sanitizedParams)]);
        }

        return $childSpan;
    }
}

if (! function_exists('finish_ai_tool_span')) {
    /**
     * Finish an AI tool execution span with result tracking
     *
     * @param  \Sentry\Tracing\Span|null  $span  The span to finish
     * @param  mixed  $result  The result from the tool execution
     */
    function finish_ai_tool_span(?\Sentry\Tracing\Span $span, $result = null): void
    {
        if (! $span) {
            return;
        }

        // Track tool result (sanitized)
        if ($result !== null) {
            $resultData = is_object($result) && method_exists($result, 'toArray')
                ? $result->toArray()
                : (is_array($result) ? $result : ['result' => $result]);

            $serialized = json_encode($resultData);
            if (strlen($serialized) > 200) {
                $serialized = substr($serialized, 0, 200) . '... [truncated]';
            }

            $span->setData(['gen_ai.tool.result' => $serialized]);
        }

        $span->finish();
    }
}

if (! function_exists('start_ai_handoff_span')) {
    /**
     * Start a Sentry AI handoff span for agent-to-agent transitions
     *
     * @param  string  $fromAgent  The agent handing off control
     * @param  string  $toAgent  The agent receiving control
     * @param  array  $context  Context being passed between agents
     */
    function start_ai_handoff_span(string $fromAgent, string $toAgent, array $context = []): ?\Sentry\Tracing\Span
    {
        $span = SentrySdk::getCurrentHub()->getSpan();
        if (! $span) {
            return null;
        }

        $childSpan = $span->startChild(new SpanContext);
        $childSpan->setOp('gen_ai.handoff');
        $childSpan->setDescription("Handoff: {$fromAgent} → {$toAgent}");

        $childSpan->setData([
            'gen_ai.handoff.from' => $fromAgent,
            'gen_ai.handoff.to' => $toAgent,
        ]);

        // Add handoff context
        if (! empty($context)) {
            $sanitizedContext = array_map(function ($value) {
                if (is_string($value) && strlen($value) > 100) {
                    return substr($value, 0, 100) . '... [truncated]';
                }

                return $value;
            }, $context);

            $childSpan->setData(['gen_ai.handoff.context' => json_encode($sanitizedContext)]);
        }

        return $childSpan;
    }
}

if (! function_exists('finish_ai_handoff_span')) {
    /**
     * Finish an AI handoff span
     *
     * @param  \Sentry\Tracing\Span|null  $span  The span to finish
     */
    function finish_ai_handoff_span(?\Sentry\Tracing\Span $span): void
    {
        if (! $span) {
            return;
        }

        $span->finish();
    }
}
