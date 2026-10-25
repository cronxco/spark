<?php

namespace App\Ai\Agents;

use App\Services\Ai\ChatClient;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;

/**
 * The agent behind {@see ChatClient}: one prompt, its
 * history and generation options, built per call rather than declared.
 */
class ChatAgent implements Agent, Conversational, HasProviderOptions, HasTools
{
    use Promptable;

    /**
     * @param  array<int, Message>  $history
     * @param  array<int, Tool>  $tools
     */
    public function __construct(
        private string $instructions,
        private array $history = [],
        private array $tools = [],
        private ?int $maxTokens = null,
        private ?float $temperature = null,
        private int $maxSteps = 1,
        private bool $json = false,
    ) {}

    public function instructions(): string
    {
        return $this->instructions;
    }

    public function messages(): iterable
    {
        return $this->history;
    }

    public function tools(): iterable
    {
        return $this->tools;
    }

    public function maxTokens(): ?int
    {
        return $this->maxTokens;
    }

    public function temperature(): ?float
    {
        return $this->temperature;
    }

    public function maxSteps(): int
    {
        return $this->maxSteps;
    }

    /**
     * JSON mode asks for a JSON object without pinning a schema; the callers'
     * prompts describe the shape and normalise what comes back.
     */
    public function providerOptions(Lab|string $provider): array
    {
        return $this->json && $provider === Lab::OpenAI
            ? ['text' => ['format' => ['type' => 'json_object']]]
            : [];
    }
}
