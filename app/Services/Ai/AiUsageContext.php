<?php

namespace App\Services\Ai;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Attribution carried alongside an AI request. It intentionally contains no
 * prompts, tool payloads, credentials, or provider response bodies.
 */
final readonly class AiUsageContext
{
    public function __construct(
        public User $user,
        public string $operation,
        public string $service = 'openai',
        public ?Integration $integration = null,
        public ?string $skill = null,
    ) {}

    public static function forModel(Model $model, string $operation = 'embedding'): ?self
    {
        $integration = match (true) {
            $model instanceof Event => $model->integration,
            $model instanceof Block => $model->event?->integration,
            default => null,
        };
        $user = $model instanceof EventObject ? $model->user : $integration?->user;

        return $user ? new self(
            user: $user,
            operation: $operation,
            service: $integration?->service ?? 'spark',
            integration: $integration,
        ) : null;
    }
}
