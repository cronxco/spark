<?php

namespace App\Traits;

use App\Models\Integration;
use Illuminate\Support\Facades\Log;

trait MigrationPauser
{
    /**
     * Pause the integration during migration to prevent automatic updates
     */
    public static function pauseDuringMigration(Integration $integration): void
    {
        $configuration = $integration->configuration ?? [];
        $configuration['paused'] = true;

        $integration->update([
            'configuration' => $configuration,
        ]);

        Log::info('Integration paused during migration', [
            'integration_id' => $integration->id,
            'service' => $integration->service,
            'instance_type' => $integration->instance_type,
        ]);
    }

    /**
     * Unpause the integration after migration completion
     */
    public static function unpauseAfterMigration(Integration $integration): void
    {
        $configuration = $integration->configuration ?? [];
        $configuration['paused'] = false;

        $integration->update([
            'configuration' => $configuration,
        ]);

        Log::info('Integration unpaused after migration completion', [
            'integration_id' => $integration->id,
            'service' => $integration->service,
            'instance_type' => $integration->instance_type,
        ]);
    }
}
