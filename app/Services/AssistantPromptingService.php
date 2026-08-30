<?php

namespace App\Services;

use App\Services\Ai\AiModel;
use Exception;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;
use RuntimeException;

class AssistantPromptingService
{
    private const MAX_RETRIES = 3;

    private const RETRY_DELAY = 2; // seconds

    /**
     * Generate a response, optionally running a tool-calling conversation.
     *
     * @param  array{model?: string, integration_id?: string, service?: string, context?: array, max_completion_tokens?: int, temperature?: float, tools?: array, tool_executor?: callable(string, array): mixed, max_tool_rounds?: int}  $options
     */
    public function generateResponse(string $prompt, array $options = []): string
    {
        $model = $options['model'] ?? AiModel::Reasoning->model();
        $integrationId = $options['integration_id'] ?? null;
        $service = $options['service'] ?? 'flint';
        $context = $options['context'] ?? [];
        $maxCompletionTokens = $options['max_completion_tokens'] ?? 8000;
        $temperature = $options['temperature'] ?? 1;
        $tools = $options['tools'] ?? null;
        $toolExecutor = $options['tool_executor'] ?? null;
        $maxToolRounds = $options['max_tool_rounds'] ?? 3;

        // Extract AI-specific metadata from context
        $promptType = $context['prompt_type'] ?? 'unknown';
        $domain = $context['domain'] ?? null;
        $mode = $context['mode'] ?? null;

        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_RETRIES) {
            try {
                if ($integrationId) {
                    log_integration_api_request(
                        $service,
                        'chat.completions',
                        'openai/chat/completions',
                        [],
                        array_merge([
                            'attempt' => $attempt + 1,
                            'prompt_type' => $promptType,
                            'domain' => $domain,
                            'mode' => $mode,
                            'model' => $model,
                        ], $context),
                        $integrationId
                    );
                }

                $messages = [['role' => 'user', 'content' => $prompt]];
                $toolRounds = 0;
                $result = null;

                while (true) {
                    // Start Sentry AI request span - one per actual API call,
                    // since a tool-calling conversation is multiple requests.
                    $aiSpan = start_ai_request_span($model, $messages, [
                        'temperature' => $temperature,
                        'max_completion_tokens' => $maxCompletionTokens,
                    ]);

                    $payload = [
                        'model' => $model,
                        'messages' => $messages,
                        'max_completion_tokens' => $maxCompletionTokens,
                    ];

                    // GPT-5 reasoning models reject any temperature other than
                    // their default (1) with a 400 error, so it's only sent
                    // for models that actually support it.
                    if (! $this->isReasoningModel($model)) {
                        $payload['temperature'] = $temperature;
                    }

                    if ($tools !== null) {
                        $payload['tools'] = $tools;
                    }

                    $response = OpenAI::chat()->create($payload);
                    $message = $response->choices[0]->message;

                    // Finish AI request span with token usage
                    $usage = $response->usage ? $response->usage->toArray() : [];
                    $finishReason = $response->choices[0]->finishReason ?? null;
                    finish_ai_request_span($aiSpan, $usage, $finishReason);

                    if ($integrationId) {
                        log_integration_api_response(
                            $service,
                            'chat.completions',
                            'openai/chat/completions',
                            200,
                            json_encode($response->toArray()),
                            [
                                'prompt_type' => $promptType,
                                'domain' => $domain,
                                'mode' => $mode,
                                'model' => $model,
                                'tool_round' => $toolRounds,
                                'tokens' => [
                                    'prompt' => $usage['promptTokens'] ?? 0,
                                    'completion' => $usage['completionTokens'] ?? 0,
                                    'total' => $usage['totalTokens'] ?? 0,
                                ],
                                'finish_reason' => $finishReason,
                            ],
                            $integrationId
                        );
                    }

                    if (empty($message->toolCalls) || $toolExecutor === null || $toolRounds >= $maxToolRounds) {
                        $result = $message->content ?? '';

                        break;
                    }

                    $messages[] = $message->toArray();

                    foreach ($message->toolCalls as $toolCall) {
                        $arguments = json_decode($toolCall->function->arguments, true) ?? [];
                        $toolResult = ($toolExecutor)($toolCall->function->name, $arguments);

                        $messages[] = [
                            'role' => 'tool',
                            'tool_call_id' => $toolCall->id,
                            'content' => is_string($toolResult) ? $toolResult : json_encode($toolResult),
                        ];
                    }

                    $toolRounds++;
                }

                return $result;

            } catch (Exception $e) {
                $lastException = $e;
                $attempt++;

                Log::warning('Agent OpenAI call failed', [
                    'service' => $service,
                    'integration_id' => $integrationId,
                    'context' => $context,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < self::MAX_RETRIES) {
                    sleep(self::RETRY_DELAY * $attempt);
                }
            }
        }

        throw new RuntimeException(
            'Failed to generate agent response after ' . self::MAX_RETRIES . ' attempts: ' .
            $lastException->getMessage()
        );
    }

    /**
     * GPT-5 and o-series reasoning models reject a non-default temperature
     * value with a 400 error, so callers need to know when to omit it.
     */
    private function isReasoningModel(string $model): bool
    {
        return (bool) preg_match('/^(gpt-5|o1|o3|o4)(-|$)/', $model);
    }
}
