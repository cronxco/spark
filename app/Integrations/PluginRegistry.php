<?php

namespace App\Integrations;

use App\Integrations\Contracts\IntegrationPlugin;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PluginRegistry
{
    private static array $plugins = [];

    public static function register(string $pluginClass): void
    {
        if (! is_subclass_of($pluginClass, IntegrationPlugin::class)) {
            throw new InvalidArgumentException('Class must implement IntegrationPlugin');
        }

        $identifier = $pluginClass::getIdentifier();
        self::$plugins[$identifier] = $pluginClass;
    }

    public static function getPlugin(string $identifier): ?string
    {
        return self::$plugins[$identifier] ?? null;
    }

    public static function getAllPlugins(): Collection
    {
        return collect(self::$plugins);
    }

    public static function getOAuthPlugins(): Collection
    {
        return self::getAllPlugins()->filter(function ($pluginClass) {
            return $pluginClass::getServiceType() === 'oauth';
        });
    }

    public static function getWebhookPlugins(): Collection
    {
        return self::getAllPlugins()->filter(function ($pluginClass) {
            return $pluginClass::getServiceType() === 'webhook';
        });
    }

    public static function getManualPlugins(): Collection
    {
        return self::getAllPlugins()->filter(function ($pluginClass) {
            return $pluginClass::getServiceType() === 'manual';
        });
    }

    public static function getApiKeyPlugins(): Collection
    {
        return self::getAllPlugins()->filter(function ($pluginClass) {
            return $pluginClass::getServiceType() === 'apikey';
        });
    }

    public static function getPluginInstance(string $identifier): ?IntegrationPlugin
    {
        $pluginClass = self::getPlugin($identifier);
        if (! $pluginClass) {
            return null;
        }

        return new $pluginClass;
    }

    /**
     * Get all plugins with their configuration metadata
     */
    public static function getPluginsWithConfig(): Collection
    {
        return self::getAllPlugins()->map(function ($pluginClass) {
            return [
                'identifier' => $pluginClass::getIdentifier(),
                'display_name' => $pluginClass::getDisplayName(),
                'description' => $pluginClass::getDescription(),
                'service_type' => $pluginClass::getServiceType(),
                'icon' => $pluginClass::getIcon(),
                'accent_color' => $pluginClass::getAccentColor(),
                'domain' => $pluginClass::getDomain(),
                'action_types' => $pluginClass::getActionTypes(),
                'block_types' => $pluginClass::getBlockTypes(),
                'object_types' => $pluginClass::getObjectTypes(),
                'instance_types' => $pluginClass::getInstanceTypes(),
            ];
        });
    }

    /**
     * Get a specific plugin's configuration metadata
     */
    public static function getPluginConfig(string $identifier): ?array
    {
        $pluginClass = self::getPlugin($identifier);
        if (! $pluginClass) {
            return null;
        }

        return [
            'identifier' => $pluginClass::getIdentifier(),
            'display_name' => $pluginClass::getDisplayName(),
            'description' => $pluginClass::getDescription(),
            'service_type' => $pluginClass::getServiceType(),
            'icon' => $pluginClass::getIcon(),
            'accent_color' => $pluginClass::getAccentColor(),
            'domain' => $pluginClass::getDomain(),
            'action_types' => $pluginClass::getActionTypes(),
            'block_types' => $pluginClass::getBlockTypes(),
            'object_types' => $pluginClass::getObjectTypes(),
            'instance_types' => $pluginClass::getInstanceTypes(),
        ];
    }
}
