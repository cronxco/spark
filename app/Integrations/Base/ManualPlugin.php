<?php

namespace App\Integrations\Base;

use App\Integrations\Contracts\IntegrationPlugin;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

abstract class ManualPlugin implements IntegrationPlugin
{
    public static function getServiceType(): string
    {
        return 'manual';
    }

    public static function supportsMigration(): bool
    {
        return false;
    }

    /**
     * Default stale time for manual integrations: 30 days
     * Override in child classes to customize
     */
    public static function getTimeUntilStaleMinutes(): ?int
    {
        return 30 * 24 * 60; // 30 days
    }

    public function initializeGroup(User $user): IntegrationGroup
    {
        return IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => static::getIdentifier(),
            'account_id' => null,
            'access_token' => null,
            'refresh_token' => null,
            'expiry' => null,
            'refresh_expiry' => null,
        ]);
    }

    public function createInstance(IntegrationGroup $group, string $instanceType, array $initialConfig = [], bool $withMigration = false): Integration
    {
        // Derive a sensible default name from plugin instance types if available
        $defaultName = static::getDisplayName();
        if (method_exists(static::class, 'getInstanceTypes')) {
            try {
                $types = static::getInstanceTypes();
                $defaultName = $types[$instanceType]['label'] ?? ucfirst($instanceType);
            } catch (Throwable $e) {
                $defaultName = ucfirst($instanceType);
            }
        } else {
            $defaultName = ucfirst($instanceType);
        }

        // If creating with migration, pause the integration by default
        $config = $initialConfig;
        if ($withMigration && static::supportsMigration()) {
            $config['paused'] = true;
        }

        return Integration::create([
            'user_id' => $group->user_id,
            'integration_group_id' => $group->id,
            'service' => static::getIdentifier(),
            'name' => $defaultName,
            'instance_type' => $instanceType,
            'configuration' => $config,
        ]);
    }

    public function handleOAuthCallback(Request $request, IntegrationGroup $group): void
    {
        // Manual integrations don't use OAuth
        throw new InvalidArgumentException('Manual integrations do not support OAuth');
    }

    public function handleWebhook(Request $request, Integration $integration): void
    {
        // Manual integrations don't use webhooks
        throw new InvalidArgumentException('Manual integrations do not support webhooks');
    }

    public function fetchData(Integration $integration): void
    {
        // Manual integrations don't fetch data from external APIs
        // Data is entered by the user
    }

    public function convertData(array $externalData, Integration $integration): array
    {
        // Manual integrations don't convert external data
        return $externalData;
    }
}
