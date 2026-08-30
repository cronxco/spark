<?php

namespace App\Services\Ai;

use Exception;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;
use RuntimeException;

/**
 * The single path every chat completion in Spark takes.
 *
 * Owns Sentry tracing, the reasoning-model temperature guard, retries, the
 * tool-calling conversation, JSON decoding and integration logging, so no call
 * site has to reimplement any of it — and so no call site can quietly skip the
 * tracing.
 */
class ChatClient
{
    private const RETRY_DELAY_SECONDS = 2;

    /**
     * Run a completion and return the assistant's text.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array{temperature?: float, max_completion_tokens?: int, json?: bool, tools?: array, tool_executor?: callable(string, array): mixed, max_tool_rounds?: int, retries?: int, service?: string, integration_id?: string, context?: array<string, mixed>}  $options
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
     * @param  array<int, array<string, mixed>>  $messages
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
     * One completion, or a whole tool-calling conversation when a tool
     * executor is supplied. Each round is its own traced API call.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     */
    private function converse(string $model, array $messages, array $options): string
    {
        $toolExecutor = $options['tool_executor'] ?? null;
        $maxToolRounds = $options['max_tool_rounds'] ?? 3;
        $toolRounds = 0;

        while (true) {
            $payload = $this->payload($model, $messages, $options);

            $span = start_ai_request_span($model, $messages, [
                'temperature' => $options['temperature'] ?? null,
                'max_completion_tokens' => $options['max_completion_tokens'] ?? null,
            ]);

            $this->logRequest($model, $options, $toolRounds);

            $response = OpenAI::chat()->create($payload);
            $message = $response->choices[0]->message;

            $usage = $response->usage ? $response->usage->toArray() : [];
            $finishReason = $response->choices[0]->finishReason ?? null;
            finish_ai_request_span($span, $usage, $finishReason);

            $this->logResponse($model, $options, $response, $usage, $finishReason, $toolRounds);

            if (empty($message->toolCalls) || $toolExecutor === null || $toolRounds >= $maxToolRounds) {
                return $message->content ?? '';
            }

            $messages[] = $message->toArray();

            foreach ($message->toolCalls as $toolCall) {
                $arguments = json_decode($toolCall->function->arguments, true) ?? [];
                $toolSpan = start_ai_tool_span($toolCall->function->name, $arguments);
                $toolResult = ($toolExecutor)($toolCall->function->name, $arguments);
                finish_ai_tool_span($toolSpan, $toolResult);

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall->id,
                    'content' => is_string($toolResult) ? $toolResult : json_encode($toolResult),
                ];
            }

            $toolRounds++;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function payload(string $model, array $messages, array $options): array
    {
        $payload = ['model' => $model, 'messages' => $messages];

        if (isset($options['max_completion_tokens'])) {
            $payload['max_completion_tokens'] = $options['max_completion_tokens'];
        }

        // GPT-5 and o-series reasoning models reject any temperature other than
        // their default with a 400, so it is only sent where it is supported.
        if (isset($options['temperature']) && ! $this->isReasoningModel($model)) {
            $payload['temperature'] = $options['temperature'];
        }

        if (! empty($options['json'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        if (isset($options['tools'])) {
            $payload['tools'] = $options['tools'];
        }

        return $payload;
    }

    private function isReasoningModel(string $model): bool
    {
        return (bool) preg_match('/^(gpt-5|o1|o3|o4)(-|$)/', $model);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function logRequest(string $model, array $options, int $toolRound): void
    {
        if (empty($options['integration_id'])) {
            return;
        }

        log_integration_api_request(
            $options['service'] ?? 'openai',
            'chat.completions',
            'openai/chat/completions',
            [],
            array_merge($options['context'] ?? [], ['model' => $model, 'tool_round' => $toolRound]),
            $options['integration_id']
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $usage
     */
    private function logResponse(string $model, array $options, mixed $response, array $usage, ?string $finishReason, int $toolRound): void
    {
        if (empty($options['integration_id'])) {
            return;
        }

        log_integration_api_response(
            $options['service'] ?? 'openai',
            'chat.completions',
            'openai/chat/completions',
            200,
            json_encode($response->toArray()),
            array_merge($options['context'] ?? [], [
                'model' => $model,
                'tool_round' => $toolRound,
                'tokens' => [
                    'prompt' => $usage['promptTokens'] ?? 0,
                    'completion' => $usage['completionTokens'] ?? 0,
                    'total' => $usage['totalTokens'] ?? 0,
                ],
                'finish_reason' => $finishReason,
            ]),
            $options['integration_id']
        );
    }
}
