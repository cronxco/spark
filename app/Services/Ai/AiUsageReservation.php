<?php

namespace App\Services\Ai;

final readonly class AiUsageReservation
{
    public function __construct(
        public string $id,
        public string $eventId,
        public string $model,
        public string $localDate,
        public AiUsageContext $context,
        public ?string $requestHash = null,
    ) {}
}
