<?php

namespace App\Integrations\Base;

use App\Integrations\Contracts\IntegrationPlugin;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use InvalidArgumentException;
use Throwable;

abstract class ManualPlugin implements IntegrationPlugin
{
    public static function getServiceType(): string
    {
        return 'manual';
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

    public function createInstance(IntegrationGroup $group, string $instanceType, array $initialConfig = []): Integration
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

        return Integration::create([
            'user_id' => $group->user_id,
            'integration_group_id' => $group->id,
            'service' => static::getIdentifier(),
            'name' => $defaultName,
            'instance_type' => $instanceType,
            'configuration' => $initialConfig,
        ]);
    }

    public function handleOAuthCallback(\Illuminate\Http\Request $request, IntegrationGroup $group): void
    {
        // Manual integrations don't use OAuth
        throw new InvalidArgumentException('Manual integrations do not support OAuth');
    }

    public function handleWebhook(\Illuminate\Http\Request $request, Integration $integration): void
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
