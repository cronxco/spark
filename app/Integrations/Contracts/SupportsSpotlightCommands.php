<?php

namespace App\Integrations\Contracts;

/**
 * Interface for integration plugins that provide Spotlight commands.
 *
 * Plugins implementing this interface can register custom Spotlight commands
 * that will be automatically discovered and registered by the SpotlightServiceProvider.
 */
interface SupportsSpotlightCommands
{
    /**
     * Return array of Spotlight command definitions.
     *
     * Each command should include:
     * - title: Display name of the command
     * - subtitle: Optional description
     * - icon: Icon name (Heroicon)
     * - action: Action type ('jump_to', 'dispatch_event', or custom action name)
     * - actionParams: Parameters for the action
     * - priority: Optional priority for ordering (default: 5)
     *
     * @return array<string, array{
     *     title: string,
     *     subtitle?: string,
     *     icon?: string,
     *     action: string,
     *     actionParams: array,
     *     priority?: int
     * }>
     */
    public static function getSpotlightCommands(): array;
}
