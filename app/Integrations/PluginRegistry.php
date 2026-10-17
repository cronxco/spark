<?php

namespace App\Integrations;

use App\Integrations\Contracts\IntegrationPlugin;
use App\Integrations\Contracts\SupportsEffects;
use App\Integrations\Contracts\SupportsSpotlightCommands;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PluginRegistry
{
    private static array $plugins = [];

    private static ?Collection $cachedSpotlightCommands = null;

    /**
     * Services that write events or integrations without a plugin. They have no
     * group and declare nothing, so integrity checks leave them out.
     *
     * openai: the per-day AI usage ledger written by AiUsageRecorder.
     */
    public const INTERNAL_SERVICES = ['openai'];

    /**
     * Get the list of valid domains that plugins can use
     */
    public static function getValidDomains(): array
    {
        return ['health', 'money', 'media', 'knowledge', 'online'];
    }

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

    /**
     * Retired type names a plugin still has on old rows, from its optional
     * static getLegacyTypes(). Recognised by integrity checks, never offered
     * for new writes.
     *
     * @param  'action_types'|'block_types'|'object_types'  $kind
     * @return array<int, string>
     */
    public static function getLegacyTypeNames(string $pluginClass, string $kind): array
    {
        if (! method_exists($pluginClass, 'getLegacyTypes')) {
            return [];
        }

        return array_keys($pluginClass::getLegacyTypes()[$kind] ?? []);
    }

    /**
     * Object types are shared across plugins (a day, a user profile), so an
     * object type counts as known when any plugin declares it.
     *
     * @return array<int, string>
     */
    public static function getAllObjectTypeNames(): array
    {
        return self::getAllPlugins()
            ->flatMap(fn (string $pluginClass) => array_merge(
                array_keys($pluginClass::getObjectTypes()),
                self::getLegacyTypeNames($pluginClass, 'object_types'),
            ))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether the user may see and add this plugin. A plugin opts in to
     * being admin-only with a static `isAdminOnly()` returning true.
     */
    public static function isAvailableTo(string $pluginClass, ?User $user): bool
    {
        $isAdminOnly = method_exists($pluginClass, 'isAdminOnly') && $pluginClass::isAdminOnly();

        return ! $isAdminOnly || (bool) $user?->is_admin;
    }

    /**
     * The plugins the user may see and add, for add-integration lists.
     */
    public static function getPluginsAvailableTo(?User $user): Collection
    {
        return self::getAllPlugins()->filter(fn (string $pluginClass): bool => self::isAvailableTo($pluginClass, $user));
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

    /**
     * Get Spotlight commands from all plugins that support them.
     * Results are cached per-request to avoid recalculating on every keystroke.
     *
     * @return Collection<array{plugin: string, key: string, command: array}>
     */
    public static function getSpotlightCommands(): Collection
    {
        if (self::$cachedSpotlightCommands !== null) {
            return self::$cachedSpotlightCommands;
        }

        self::$cachedSpotlightCommands = self::getAllPlugins()
            ->filter(fn ($pluginClass) => is_subclass_of($pluginClass, SupportsSpotlightCommands::class))
            ->flatMap(function ($pluginClass) {
                $identifier = $pluginClass::getIdentifier();
                $commands = $pluginClass::getSpotlightCommands();

                return collect($commands)->map(function ($command, $key) use ($identifier) {
                    return [
                        'plugin' => $identifier,
                        'key' => $key,
                        'command' => $command,
                    ];
                });
            });

        return self::$cachedSpotlightCommands;
    }

    /**
     * Clear the cached spotlight commands (useful for testing).
     */
    public static function clearSpotlightCommandsCache(): void
    {
        self::$cachedSpotlightCommands = null;
    }

    /**
     * Get effects for a specific service.
     *
     * @return array<string, array>
     */
    public static function getEffects(string $service): array
    {
        $pluginClass = self::getPlugin($service);

        if (! $pluginClass) {
            return [];
        }

        if (! is_subclass_of($pluginClass, SupportsEffects::class)) {
            return [];
        }

        return $pluginClass::getEffects();
    }

    /**
     * Get all effects from all plugins that support them.
     *
     * @return Collection<array{plugin: string, key: string, effect: array}>
     */
    public static function getAllEffects(): Collection
    {
        return self::getAllPlugins()
            ->filter(fn ($pluginClass) => is_subclass_of($pluginClass, SupportsEffects::class))
            ->flatMap(function ($pluginClass) {
                $identifier = $pluginClass::getIdentifier();
                $effects = $pluginClass::getEffects();

                return collect($effects)->map(function ($effect, $key) use ($identifier) {
                    return [
                        'plugin' => $identifier,
                        'key' => $key,
                        'effect' => $effect,
                    ];
                });
            });
    }
}
