<?php

namespace App\Integrations\Base;

use App\Integrations\Contracts\IntegrationPlugin;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Http\Request;
use Exception;

abstract class ManualPlugin implements IntegrationPlugin
{
    abstract public static function getIdentifier(): string;
    
    abstract public static function getDisplayName(): string;
    
    abstract public static function getDescription(): string;
    
    abstract public static function getConfigurationSchema(): array;
    
    abstract public static function getInstanceTypes(): array;
    
    public static function getServiceType(): string
    {
        return 'manual';
    }

    public function initialize(User $user): Integration
    {
        $group = $this->initializeGroup($user);

        return Integration::create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => static::getIdentifier(),
            'name' => static::getDisplayName(),
            'instance_type' => null,
            'configuration' => [],
        ]);
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
        $defaultName = static::getDisplayName();
        if (method_exists(static::class, 'getInstanceTypes')) {
            try {
                $types = static::getInstanceTypes();
                $defaultName = $types[$instanceType]['label'] ?? ucfirst($instanceType);
            } catch (\Throwable $e) {
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

    public function handleOAuthCallback(Request $request, IntegrationGroup $group): void
    {
        // Manual integrations don't use OAuth
        throw new Exception('OAuth is not supported for manual integrations');
    }

    public function handleWebhook(Request $request, Integration $integration): void
    {
        // Manual integrations don't use webhooks
        throw new Exception('Webhooks are not supported for manual integrations');
    }

    public function fetchData(Integration $integration): void
    {
        // Manual integrations don't fetch data automatically
        // Data is added by the user through the UI
    }

    public function convertData(array $externalData, Integration $integration): array
    {
        // Manual integrations don't convert external data
        return [];
    }
}