<?php

namespace App\Spotlight\Queries\Integration;

use App\Integrations\PluginRegistry;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class PluginCommandsQuery
{
    /**
     * Create Spotlight query for plugin-provided commands.
     * Automatically registers all commands from plugins that implement SupportsSpotlightCommands.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::asDefault(function (string $query) {
            if (blank($query) || strlen($query) < 2) {
                return collect();
            }

            // Get all plugin commands
            $pluginCommands = PluginRegistry::getSpotlightCommands();

            return $pluginCommands
                ->filter(function ($item) use ($query) {
                    // Filter by query
                    return str_contains(strtolower($item['command']['title']), strtolower($query));
                })
                ->map(function ($item) {
                    $command = $item['command'];

                    return SpotlightResult::make()
                        ->setTitle($command['title'])
                        ->setSubtitle($command['subtitle'] ?? '')
                        ->setTypeahead('Command: ' . $command['title'])
                        ->setIcon(normalize_icon_for_spotlight($command['icon'] ?? 'puzzle-piece'))
                        ->setGroup('integrations')
                        ->setPriority($command['priority'] ?? 1)
                        ->setAction(
                            $command['action'],
                            $command['actionParams'] ?? []
                        );
                });
        });
    }
}
