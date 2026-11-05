<?php

namespace App\Events;

use App\Models\Integration;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EffectDispatched
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Integration $integration,
        public string $effectKey,
        public array $parameters = []
    ) {}
}
