<?php

namespace App\Services\Ai;

use App\Ai\Agents\ChatAgent;
use App\Models\Event;
use App\Models\Integration;
use Exception;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\AgentResponse;
use RuntimeException;

/**
 * The single path every chat completion in Spark takes.
 *
 * Owns Sentry tracing, the reasoning-model temperature guard, retries, usage
 * accounting, JSON decoding and integration logging around a laravel/ai
 * agent, so no call site has to reimplement any of it — and so no call site
 * can quietly skip the tracing. Tool calling is laravel/ai's own loop; each
 * tool run is traced by {@see AiToolTracing}.
 */
class ChatClient
{
    private const RETRY_DELAY_SECONDS = 2;

    private const PROVIDER = 'openai';

    /**
     * Run a completion and return the assistant's text.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array{temperature?: float, max_completion_tokens?: int, json?: bool, tools?: array<int, Tool>, max_tool_rounds?: int, retries?: int, timeout?: int, service?: string, integration_id?: string, context?: array<string, mixed>, usage_context?: AiUsageContext}  $options
     */
    public function text(AiModel $role, array $messages, array $options = []): string
    {
        $model = $role->model();
        $retries = max(1, $options['retries'] ?? 1);
        $attempt = 0;
        $lastException = null;

        while ($attempt < $retries) {
            try {
                return $this->converse($model, $messages, $options);
            } catch (Exception $e) {
                $lastException = $e;
                $attempt++;

                Log::warning('AI chat call failed', [
                    'model' => $model,
                    'service' => $options['service'] ?? null,
                    'integration_id' => $options['integration_id'] ?? null,
                    'context' => $options['context'] ?? [],
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < $retries) {
                    sleep(self::RETRY_DELAY_SECONDS * $attempt);
                }
            }
        }

        throw new RuntimeException(
            "Failed to generate a response after {$retries} attempt(s): " . $lastException->getMessage(),
            0,
            $lastException
        );
    }

    /**
     * Run a completion in JSON mode and return the decoded payload.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function json(AiModel $role, array $messages, array $options = []): array
    {
        $content = $this->text($role, $messages, array_merge($options, ['json' => true]));
        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Model response was not valid JSON: ' . json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * One prompt to the agent. With tools, laravel/ai runs the tool-calling
     * conversation for up to `max_tool_rounds` rounds before a final answer.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    private function converse(string $model, array $messages, array $options): string
    {
        [$agent, $prompt] = $this->agent($model, $messages, $options);
        $usageContext = $this->usageContext($options);
        $reservation = $usageContext ? app(AiUsageRecorder::class)->reserve(
            $usageContext,
            $model,
            $this->estimatedReservation($messages, $options),
        ) : null;

        $span = start_ai_request_span($model, $messages, [
            'temperature' => $options['temperature'] ?? null,
            'max_completion_tokens' => $options['max_completion_tokens'] ?? null,
        ]);

        $this->logRequest($model, $options);

        $usage = [];
        $finishReason = null;
        $requestSucceeded = false;

        try {
            $response = $agent->prompt($prompt, provider: self::PROVIDER, model: $model, timeout: $options['timeout'] ?? null);
            $usage = $response->usage->toArray();
            $finishReason = $response->steps->last()?->finishReason->value;
            $requestSucceeded = true;
        } catch (Exception $exception) {
            if ($reservation) {
                $this->failAccountingSafely($reservation);
            }

            throw $exception;
        } finally {
            finish_ai_request_span($span, $usage, $finishReason, $requestSucceeded);
        }

        if ($reservation) {
            $this->completeAccountingSafely($reservation, $response, $response->raw?->json('id'));
        }

        $this->logResponse($model, $options, $response, $finishReason);

        return $response->text;
    }

    /**
     * Split OpenAI-style messages into the agent's instructions, history and
     * the prompt itself: system messages become the instructions and the last
     * message is the prompt.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array{0: ChatAgent, 1: string}
     */
    private function agent(string $model, array $messages, array $options): array
    {
        $instructions = [];
        $history = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $instructions[] = (string) $message['content'];
            } else {
                $history[] = new Message($message['role'], (string) $message['content']);
            }
        }

        $last = array_pop($history);
        if ($last === null) {
            throw new RuntimeException('A chat completion needs at least one non-system message.');
        }

        $tools = $options['tools'] ?? [];

        $agent = new ChatAgent(
            instructions: implode("\n\n", $instructions),
            history: $history,
            tools: $tools,
            maxTokens: $options['max_completion_tokens'] ?? null,
            // GPT-5 and o-series reasoning models reject any temperature other
            // than their default with a 400, so it is only sent where supported.
            temperature: isset($options['temperature']) && ! $this->isReasoningModel($model) ? (float) $options['temperature'] : null,
            maxSteps: $tools === [] ? 1 : ($options['max_tool_rounds'] ?? 3) + 1,
            json: ! empty($options['json']),
        );

        return [$agent, (string) $last->content];
    }

    private function isReasoningModel(string $model): bool
    {
        return (bool) preg_match('/^(?:gpt-5(?:[.-].+)?|o(?:1|3|4)(?:[.-].+)?)$/i', $model);
    }

    /** @param array<string, mixed> $options */
    private function usageContext(array $options): ?AiUsageContext
    {
        if (($options['usage_context'] ?? null) instanceof AiUsageContext) {
            return $options['usage_context'];
        }

        $integrationId = $options['integration_id'] ?? data_get($options, 'context.integration_id');
        if (! empty($integrationId)) {
            $integration = Integration::query()->with('user')->find($integrationId);
            if ($integration?->user) {
                return new AiUsageContext(
                    user: $integration->user,
                    operation: (string) data_get($options, 'context.operation', 'chat'),
                    service: (string) ($options['service'] ?? $integration->service ?? 'openai'),
                    integration: $integration,
                );
            }
        }

        $eventId = data_get($options, 'context.event_id');
        if (is_string($eventId) && $eventId !== '') {
            $event = Event::query()->with('integration.user')->find($eventId);
            if ($event?->integration?->user) {
                return new AiUsageContext(
                    user: $event->integration->user,
                    operation: (string) data_get($options, 'context.operation', 'chat'),
                    service: $event->service,
                    integration: $event->integration,
                );
            }
        }

        $user = auth()->user();

        return $user ? new AiUsageContext(
            user: $user,
            operation: (string) data_get($options, 'context.operation', 'chat'),
            service: (string) ($options['service'] ?? 'openai'),
        ) : null;
    }

    /** @param array<int, array<string, mixed>> $messages @param array<string, mixed> $options */
    private function estimatedReservation(array $messages, array $options): ?int
    {
        $input = max(1, (int) ceil(strlen(json_encode($messages) ?: '') / 4));

        return isset($options['max_completion_tokens'])
            ? $input + max(0, (int) $options['max_completion_tokens'])
            : null;
    }

    private function completeAccountingSafely(AiUsageReservation $reservation, AgentResponse $response, ?string $requestId): void
    {
        try {
            app(AiUsageRecorder::class)->complete($reservation, AiTokenUsage::fromSdk($response->usage), $requestId);
        } catch (Exception $exception) {
            report($exception);
            Log::error('AI usage accounting failed after a completed chat request', [
                'operation' => $reservation->context->operation,
                'error' => redact_sensitive_urls($exception->getMessage()),
            ]);
        }
    }

    private function failAccountingSafely(AiUsageReservation $reservation): void
    {
        try {
            app(AiUsageRecorder::class)->fail($reservation);
        } catch (Exception $exception) {
            report($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function logRequest(string $model, array $options): void
    {
        if (empty($options['integration_id'])) {
            return;
        }

        log_integration_api_request(
            $options['service'] ?? 'openai',
            'responses',
            'openai/responses',
            [],
            array_merge($options['context'] ?? [], ['model' => $model]),
            $options['integration_id']
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function logResponse(string $model, array $options, AgentResponse $response, ?string $finishReason): void
    {
        if (empty($options['integration_id'])) {
            return;
        }

        log_integration_api_response(
            $options['service'] ?? 'openai',
            'responses',
            'openai/responses',
            200,
            json_encode(['text' => $response->text, 'usage' => $response->usage->toArray()]),
            array_merge($options['context'] ?? [], [
                'model' => $model,
                'steps' => $response->steps->count(),
                'tool_calls' => $response->toolCalls->count(),
                'tokens' => [
                    'prompt' => $response->usage->inputTokens,
                    'completion' => $response->usage->outputTokens,
                    'total' => $response->usage->totalTokens(),
                ],
                'finish_reason' => $finishReason,
            ]),
            $options['integration_id']
        );
    }
}
