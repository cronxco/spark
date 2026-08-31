<?php

namespace App\Services\Flint\Routines;

/**
 * The outcome of handing a routine to a driver, in the shape the trigger jobs
 * record against TaskExecution.
 */
class RoutineResult
{
    /**
     * @param  array<string, mixed>  $details
     */
    private function __construct(
        public readonly string $status,
        public readonly ?string $error = null,
        public readonly array $details = [],
    ) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public static function success(array $details = []): self
    {
        return new self('success', null, $details);
    }

    /**
     * Nothing to do — the routine has no driver or no endpoint configured.
     */
    public static function notApplicable(string $reason): self
    {
        return new self('not_applicable', null, ['reason' => $reason]);
    }
}
