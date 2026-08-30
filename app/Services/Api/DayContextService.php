<?php

namespace App\Services\Api;

use App\Models\Integration;
use App\Models\User;
use App\Services\AssistantContextService;
use Carbon\Carbon;

/** Shared, bounded day-context command for non-MCP transports. */
class DayContextService
{
    public function __construct(private AssistantContextService $context) {}

    public function forDay(User $user, Carbon $date, ?array $domains = null): array
    {
        $integration = $user->integrations()->where('service', 'flint')->first()
            ?? new Integration([
                'user_id' => $user->id,
                'service' => 'flint',
                'name' => 'API Context',
                'configuration' => [
                    'today_enabled' => true,
                    'include_relationships' => true,
                    'max_events_per_timeframe' => 200,
                ],
            ]);

        return $this->context->generateTimeframeContext($user, 'today', $date, $integration, $domains);
    }
}
