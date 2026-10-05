<?php

namespace App\Services\Jev;

use App\Services\Jev\Exceptions\JevResponseException;
use App\Services\Jev\Exceptions\JevUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;

/**
 * Client for TypeSafe's Jev (System One) API.
 *
 * Jev answers typed questions about a piece of state with probabilities. It
 * never generates text, so every answer is validated against the question
 * that was asked before anything acts on it.
 */
class JevClient
{
    private const MAX_ATTEMPTS = 3;

    private const MIN_ATTEMPT_SECONDS = 0.2;

    private const PROBABILITY_TOLERANCE = 0.05;

    /**
     * Some responses include an implicit catch-all option alongside the
     * declared ones.
     */
    private const IMPLICIT_OPTIONS = ['none_of_the_above'];

    public function isConfigured(): bool
    {
        return (bool) config('services.jev.enabled', false)
            && filled(config('services.jev.api_key'));
    }

    /**
     * Ask a batch of questions about one state in a single call.
     *
     * The whole call, including retries, fits within $budgetSeconds.
     *
     * @param  array<string, mixed>|string  $state
     * @param  array<string, JevQuestion>  $questions  Keyed by answer id
     * @param  array<string, mixed>  $logContext
     *
     * @throws JevUnavailableException when Jev cannot answer in time
     * @throws JevResponseException when Jev's answer cannot be trusted
     */
    public function ask(array|string $state, array $questions, ?float $budgetSeconds = null, array $logContext = []): JevAssessment
    {
        if (! $this->isConfigured()) {
            throw new JevUnavailableException('Jev is not configured.');
        }

        if ($questions === []) {
            throw new JevResponseException('No questions to ask Jev.');
        }

        $model = (string) config('services.jev.model', 'jev-1.13.0');
        $payload = [
            'state' => $state,
            'model' => $model,
            'questions' => array_map(fn (JevQuestion $question): array => $question->toPayload(), $questions),
        ];

        $span = $this->startSpan($model, count($questions));
        $succeeded = false;

        try {
            $response = $this->send($payload, $budgetSeconds ?? (float) config('services.jev.timeout', 10), $logContext);
            $assessment = $this->parse($response, $questions, $model);
            $succeeded = true;

            Log::debug('Jev: Assessment received', $logContext + [
                'model' => $assessment->model,
                'questions' => count($questions),
                'input_tokens' => $assessment->inputTokens,
            ]);

            return $assessment;
        } finally {
            $span?->setData(['gen_ai.request.success' => $succeeded]);
            $span?->finish();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $logContext
     */
    private function send(array $payload, float $budgetSeconds, array $logContext): Response
    {
        $deadline = microtime(true) + $budgetSeconds;
        $url = rtrim((string) config('services.jev.base_url', 'https://api.typesafe.ai'), '/') . '/v1/systemone';
        $lastError = 'no attempt made';

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $remaining = $deadline - microtime(true);

            if ($remaining < self::MIN_ATTEMPT_SECONDS) {
                break;
            }

            try {
                $response = Http::withToken((string) config('services.jev.api_key'))
                    ->acceptJson()
                    ->asJson()
                    ->connectTimeout(max(0.1, min(2.0, $remaining)))
                    ->timeout(max(0.1, $remaining))
                    ->post($url, $payload);
            } catch (ConnectionException $e) {
                $lastError = $e->getMessage();
                Log::info('Jev: Request failed to connect', $logContext + ['attempt' => $attempt, 'error' => $lastError]);
                $this->backoff($attempt, null, $deadline);

                continue;
            }

            if ($response->successful()) {
                return $response;
            }

            $status = $response->status();
            $lastError = "HTTP {$status}";

            if ($status === 429 || $status === 529 || $response->serverError()) {
                Log::info('Jev: Retryable response', $logContext + ['attempt' => $attempt, 'status' => $status]);
                $this->backoff($attempt, $response->header('retry-after'), $deadline);

                continue;
            }

            throw new JevResponseException("Jev rejected the request with HTTP {$status}: " . mb_substr($response->body(), 0, 300));
        }

        throw new JevUnavailableException("Jev did not answer within {$budgetSeconds}s ({$lastError}).");
    }

    private function backoff(int $attempt, ?string $retryAfter, float $deadline): void
    {
        $delay = is_numeric($retryAfter) ? (float) $retryAfter : 0.25 * (2 ** ($attempt - 1));
        $delay = min($delay, max(0.0, $deadline - microtime(true) - self::MIN_ATTEMPT_SECONDS));

        if ($delay > 0) {
            Sleep::for((int) round($delay * 1000))->milliseconds();
        }
    }

    /**
     * @param  array<string, JevQuestion>  $questions
     */
    private function parse(Response $response, array $questions, string $requestedModel): JevAssessment
    {
        $body = $response->json();

        if (! is_array($body) || ! is_array($body['answers'] ?? null) || ! is_string($body['model'] ?? null)) {
            throw new JevResponseException('Jev returned a malformed response.');
        }

        if (! str_ends_with($requestedModel, '-latest') && ! str_ends_with($requestedModel, '-preview') && $body['model'] !== $requestedModel) {
            throw new JevResponseException("Jev answered with model {$body['model']} but {$requestedModel} was requested.");
        }

        $answers = [];

        foreach ($questions as $id => $question) {
            $answer = $body['answers'][$id] ?? null;

            if (! is_array($answer) || ($answer['type'] ?? null) !== $question->type) {
                throw new JevResponseException("Jev returned no {$question->type} answer for '{$id}'.");
            }

            $this->validateAnswer((string) $id, $question, $answer);
            $answers[$id] = $answer;
        }

        $inputTokens = $body['usage']['input_tokens'] ?? null;

        return new JevAssessment($body['model'], $answers, is_numeric($inputTokens) ? (int) $inputTokens : null);
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function validateAnswer(string $id, JevQuestion $question, array $answer): void
    {
        if ($question->type === JevQuestion::NOUL) {
            $this->assertProbability($id, $answer['noul'] ?? null);

            return;
        }

        $allowed = array_merge($question->options(), self::IMPLICIT_OPTIONS);
        $probabilities = $answer['probabilities'] ?? null;

        if (! is_array($probabilities) || $probabilities === []) {
            throw new JevResponseException("Jev returned no probabilities for '{$id}'.");
        }

        foreach ($probabilities as $option => $probability) {
            if (! in_array((string) $option, $allowed, true)) {
                throw new JevResponseException("Jev returned an undeclared option '{$option}' for '{$id}'.");
            }

            $this->assertProbability($id, $probability);
        }

        if (abs(array_sum($probabilities) - 1.0) > self::PROBABILITY_TOLERANCE) {
            throw new JevResponseException("Jev probabilities for '{$id}' do not sum to 1.");
        }

        if ($question->type === JevQuestion::CHOICE && ! in_array((string) ($answer['choice'] ?? ''), $allowed, true)) {
            throw new JevResponseException("Jev chose an undeclared option for '{$id}'.");
        }

        if ($question->type === JevQuestion::SCORE) {
            $score = $answer['score'] ?? null;

            if (! is_numeric($score) || $score < 0 || $score > count($question->options()) - 1) {
                throw new JevResponseException("Jev returned an out-of-range score for '{$id}'.");
            }
        }
    }

    private function assertProbability(string $id, mixed $value): void
    {
        if (! is_numeric($value) || $value < 0 || $value > 1) {
            throw new JevResponseException("Jev returned an invalid probability for '{$id}'.");
        }
    }

    private function startSpan(string $model, int $questionCount): ?Span
    {
        $parent = SentrySdk::getCurrentHub()->getSpan();

        if (! $parent) {
            return null;
        }

        $span = $parent->startChild(new SpanContext);
        $span->setOp('gen_ai.request');
        $span->setDescription("Jev {$model}");
        $span->setData([
            'gen_ai.system' => 'typesafe',
            'gen_ai.request.model' => $model,
            'jev.question_count' => $questionCount,
        ]);

        return $span;
    }
}
