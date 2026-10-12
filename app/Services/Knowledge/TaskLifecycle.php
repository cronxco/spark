<?php

namespace App\Services\Knowledge;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class TaskLifecycle
{
    public function schedule(array $task): array
    {
        $kind = $task['lifecycle'] ?? 'persistent';
        $start = $task['window_start_on'] ?? $task['availability_on'] ?? null;
        if ($kind !== 'persistent' && ! $start) {
            throw ValidationException::withMessages(['availability_on' => 'Choose a date for this task.']);
        }
        $task['expires_on'] = $kind === 'persistent' ? null : CarbonImmutable::parse($start)->addDays($kind === 'day' ? 1 : 7)->toDateString();

        return $task;
    }

    public function status(array $task, string $date): string
    {
        $status = $task['status'] ?? 'open';
        if ($status !== 'open') {
            return $status;
        }

        return ! empty($task['expires_on']) && $date >= $task['expires_on'] ? 'expired' : 'open';
    }

    public function visible(array $task, string $date): bool
    {
        return ! empty($task['availability_on']) && $task['availability_on'] <= $date && $this->status($task, $date) === 'open';
    }
}
