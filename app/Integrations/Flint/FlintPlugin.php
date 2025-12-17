<?php

namespace App\Integrations\Flint;

use App\Integrations\Base\ManualPlugin;

class FlintPlugin extends ManualPlugin
{
    public static function getIdentifier(): string
    {
        return 'flint';
    }

    public static function getDisplayName(): string
    {
        return 'Flint';
    }

    public static function getDescription(): string
    {
        return 'AI assistant for analyzing your daily events and providing insights.';
    }

    public static function getIcon(): string
    {
        return 'fas.hexagon-nodes';
    }

    public static function getAccentColor(): string
    {
        return 'warning'; // Purple for AI features
    }

    public static function getDomain(): string
    {
        return 'online';
    }

    public static function getInstanceTypes(): array
    {
        return [
            'assistant' => [
                'label' => 'Assistant',
                'schema' => self::getConfigurationSchema('assistant'),
                'description' => 'AI assistant for analyzing your daily events',
            ],
        ];
    }

    public static function getConfigurationSchema($instanceType = null): array
    {
        return [
            // Timeframe toggles
            'yesterday_enabled' => [
                'type' => 'boolean',
                'label' => 'Include Yesterday',
                'default' => true,
            ],
            'today_enabled' => [
                'type' => 'boolean',
                'label' => 'Include Today',
                'default' => true,
            ],
            'tomorrow_enabled' => [
                'type' => 'boolean',
                'label' => 'Include Tomorrow',
                'default' => true,
            ],

            // Service/Integration filters (stored as JSON arrays)
            'yesterday_services' => [
                'type' => 'array',
                'label' => 'Yesterday Services (JSON)',
                'description' => 'Leave empty to include all services',
                'default' => [],
            ],
            'today_services' => [
                'type' => 'array',
                'label' => 'Today Services (JSON)',
                'default' => [],
            ],
            'tomorrow_services' => [
                'type' => 'array',
                'label' => 'Tomorrow Services (JSON)',
                'default' => [],
            ],

            // Block configuration
            'excluded_block_types' => [
                'type' => 'array',
                'label' => 'Excluded Block Types',
                'description' => 'Block types to exclude (leave empty to only exclude *_raw blocks)',
                'default' => [],
            ],

            // Options
            'include_relationships' => [
                'type' => 'boolean',
                'label' => 'Include Relationships',
                'default' => true,
            ],
            'max_events_per_timeframe' => [
                'type' => 'integer',
                'label' => 'Max Events Per Timeframe',
                'min' => 50,
                'max' => 1000,
                'default' => null, // Falls back to env
                'description' => 'Leave empty to use default from environment',
            ],
        ];
    }

    // No action types needed (assistant doesn't create events)
    public static function getActionTypes(): array
    {
        return [];
    }

    // No block types needed
    public static function getBlockTypes(): array
    {
        return [];
    }

    // No object types needed (assistant doesn't create objects)
    public static function getObjectTypes(): array
    {
        return [];
    }
}
