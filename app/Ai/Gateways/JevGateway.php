<?php

namespace App\Ai\Gateways;

use Laravel\Ai\Gateway\TypeSafeGateway;
use Throwable;

/**
 * TypeSafe's gateway with the two behaviours Jev cannot accept switched off.
 *
 * The SDK retries a throttled or overloaded response after its Retry-After
 * wait, which Jev's time budget cannot bound, so every retry is left to
 * JevClient. And it reports the requested model when the response names
 * none, which would let an unknown model pass Jev's model pin, so a missing
 * model stays missing.
 */
class JevGateway extends TypeSafeGateway
{
    protected function retryAfterSeconds(Throwable $exception): ?int
    {
        return null;
    }

    /** @param array<string, mixed> $data */
    protected function answeringModel(array $data, string $model): string
    {
        $answeredBy = $data['routing']['model'] ?? $data['model'] ?? '';

        return is_string($answeredBy) ? $answeredBy : '';
    }
}
