<?php

namespace App\Services\Flint;

use App\Models\ActionProgress;

final readonly class FlintDispatchResult
{
    public function __construct(
        public string $runUuid,
        public ActionProgress $progress,
        public string $skill,
        public string $routine,
        public string $driver,
        public string $localDate,
        public string $period,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'run_uuid' => $this->runUuid,
            'action_progress_id' => $this->progress->id,
            'skill' => $this->skill,
            'routine' => $this->routine,
            'driver' => $this->driver,
            'local_date' => $this->localDate,
            'period' => $this->period,
            'queued' => true,
        ];
    }
}
