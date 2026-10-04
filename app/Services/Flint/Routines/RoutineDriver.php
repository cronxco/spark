<?php

namespace App\Services\Flint\Routines;

use App\Models\ActionProgress;
use App\Models\User;

/**
 * How a Flint routine actually gets run.
 *
 * Spark always owns the scheduling, idempotency and TaskExecution recording;
 * a driver owns only the "now do the work" step, so the two paths stay
 * interchangeable.
 */
interface RoutineDriver
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function run(User $user, string $routine, array $payload, ?ActionProgress $progress = null): RoutineResult;
}
