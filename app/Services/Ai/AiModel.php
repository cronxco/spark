<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * The model roles Spark uses.
 *
 * Every model choice in the application resolves through this enum, so models
 * are configured in exactly one place — `services.openai.models` — and are
 * never named inline. Adding a role means adding a case here and a key there;
 * it should stay a short list.
 */
enum AiModel: string
{
    /** Vector embeddings for semantic search. */
    case Embedding = 'embedding';

    /** Cheap, high-volume structured extraction and summarisation. */
    case Extraction = 'extraction';

    /** Agentic and multi-step work: skill runs, tool-calling disambiguation. */
    case Reasoning = 'reasoning';

    /**
     * The configured model identifier for this role.
     *
     * @throws RuntimeException when the role has no model configured
     */
    public function model(): string
    {
        $model = config("services.openai.models.{$this->value}");

        if (! is_string($model) || trim($model) === '') {
            throw new RuntimeException("No OpenAI model is configured for the '{$this->value}' role.");
        }

        return $model;
    }
}
