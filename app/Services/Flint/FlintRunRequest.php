<?php

namespace App\Services\Flint;

/** A validated request to run one Flint routine. */
final readonly class FlintRunRequest
{
    public function __construct(
        public string $skill,
        public string $routine,
        public string $driver,
        public ?string $driverOverride,
        public string $timezone,
        public string $localDate,
        public string $period,
    ) {}
}
