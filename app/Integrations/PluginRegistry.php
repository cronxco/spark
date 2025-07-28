<?php

namespace App\Integrations;

use App\Integrations\Contracts\IntegrationPlugin;
use Illuminate\Support\Collection;

class PluginRegistry
{
    private static array $plugins = [];
    
    public static function register(string $pluginClass): void
    {
        if (!is_subclass_of($pluginClass, IntegrationPlugin::class)) {
            throw new \InvalidArgumentException("Class must implement IntegrationPlugin");
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
    
    public static function getPluginInstance(string $identifier): ?IntegrationPlugin
    {
        $pluginClass = self::getPlugin($identifier);
        if (!$pluginClass) {
            return null;
        }
        
        return new $pluginClass();
    }
} 