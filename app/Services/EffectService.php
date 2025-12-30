<?php

namespace App\Services;

use App\Events\EffectDispatched;
use App\Integrations\PluginRegistry;
use App\Models\Integration;
use InvalidArgumentException;

class EffectService
{
    /**
     * Dispatch an effect for the given integration.
     */
    public function dispatch(Integration $integration, string $effectKey, array $parameters = []): void
    {
        $effects = PluginRegistry::getEffects($integration->service);

        if (! isset($effects[$effectKey])) {
            throw new InvalidArgumentException(
                "Effect '{$effectKey}' not found for service '{$integration->service}'"
            );
        }

        $effect = $effects[$effectKey];
        $jobClass = $effect['jobClass'];
        $queue = $effect['queue'] ?? 'effects';

        // Dispatch the effect job
        $jobClass::dispatch($integration, $parameters)->onQueue($queue);

        // Fire event for tracking
        event(new EffectDispatched($integration, $effectKey, $parameters));
    }

    /**
     * Check if an effect exists for a service.
     */
    public function effectExists(string $service, string $effectKey): bool
    {
        $effects = PluginRegistry::getEffects($service);

        return isset($effects[$effectKey]);
    }

    /**
     * Get effect definition.
     */
    public function getEffect(string $service, string $effectKey): ?array
    {
        $effects = PluginRegistry::getEffects($service);

        return $effects[$effectKey] ?? null;
    }
}
