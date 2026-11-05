<?php

namespace App\Services\Jev;

use InvalidArgumentException;

/**
 * Validated answers from one Jev call.
 */
final readonly class JevAssessment
{
    /**
     * @param  array<string, array<string, mixed>>  $answers
     */
    public function __construct(
        public string $model,
        public array $answers,
        public ?int $inputTokens = null,
    ) {}

    public function has(string $id): bool
    {
        return isset($this->answers[$id]);
    }

    public function choice(string $id): string
    {
        return (string) $this->answer($id, JevQuestion::CHOICE)['choice'];
    }

    /**
     * @return array<string, float>
     */
    public function probabilities(string $id): array
    {
        return array_map('floatval', $this->answers[$id]['probabilities'] ?? []);
    }

    public function probability(string $id, string $option): float
    {
        return $this->probabilities($id)[$option] ?? 0.0;
    }

    public function noul(string $id): float
    {
        return (float) $this->answer($id, JevQuestion::NOUL)['noul'];
    }

    public function score(string $id): float
    {
        return (float) $this->answer($id, JevQuestion::SCORE)['score'];
    }

    public function confidence(string $id): ?float
    {
        $confidence = $this->answers[$id]['confidence'] ?? null;

        return $confidence === null ? null : (float) $confidence;
    }

    /**
     * @return array{model: string, input_tokens: ?int, answers: array<string, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'answers' => $this->answers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(string $id, string $type): array
    {
        $answer = $this->answers[$id] ?? null;

        if (! is_array($answer) || ($answer['type'] ?? null) !== $type) {
            throw new InvalidArgumentException("No {$type} answer for '{$id}'.");
        }

        return $answer;
    }
}
