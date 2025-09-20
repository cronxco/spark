<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IntegrationGroupDeletionProgress
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $userId,
        public string $integrationGroupId,
        public string $step,
        public string $message,
        public int $progress = 0,
        public int $total = 100,
        public array $details = []
    ) {}
}
