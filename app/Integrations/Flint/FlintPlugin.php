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
            // Note: User-facing settings are now managed in /settings/flint
            // This configuration schema is kept minimal for integration-level settings only

            'excluded_block_types' => [
                'type' => 'array',
                'label' => 'Excluded Block Types',
                'description' => 'Block types to exclude from analysis (leave empty to only exclude *_raw blocks)',
                'default' => [],
            ],
            'include_relationships' => [
                'type' => 'boolean',
                'label' => 'Include Relationships',
                'default' => true,
                'description' => 'Include relationship data in AI context',
            ],
            'max_events_per_timeframe' => [
                'type' => 'integer',
                'label' => 'Max Events Per Timeframe',
                'min' => 50,
                'max' => 1000,
                'default' => null,
                'description' => 'Maximum events to include in context (leave empty for default)',
            ],
        ];
    }

    public static function getActionTypes(): array
    {
        return [
            'had_summary' => [
                'display_name' => 'Had Digest',
                'display_name_past_tense' => 'Generated Digest',
                'description' => 'AI-generated daily digest of events and insights',
                'icon' => 'fas.file-lines',
                'display_with_object' => false,
                'hidden' => false,
                'exclude_from_flint' => true,
                'supports_value' => true,
                'value_formatter' => null,
                'value_multiplier' => null,
                'value_unit' => 'blocks',
            ],
            'had_analysis' => [
                'display_name' => 'Had Analysis',
                'description' => 'AI-generated analysis',
                'icon' => 'fas.hexagon-nodes-bolt',
                'display_with_object' => false,
                'hidden' => false,
                'exclude_from_flint' => true,
                'supports_value' => true,
                'value_formatter' => null,
                'value_multiplier' => null,
                'value_unit' => 'blocks',
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'flint_insight' => [
                'display_name' => 'Insight',
                'description' => 'An observation the digest drew from the day\'s data',
                'icon' => 'fas.lightbulb',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => false,
                'value_unit' => null,
            ],
            'flint_editorial_note' => [
                'display_name' => 'Editorial Note',
                'description' => 'Freeform AI commentary or editorial observation',
                'icon' => 'fas.pen-nib',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => false,
                'value_unit' => null,
            ],
            'flint_user_question' => [
                'display_name' => 'User Question',
                'description' => 'A question posed by Flint for the user to answer',
                'icon' => 'fas.circle-question',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => false,
                'value_unit' => null,
            ],
        ];
    }

    public static function getObjectTypes(): array
    {
        return [
            'topic' => [
                'icon' => 'fas.compass',
                'display_name' => 'Topic',
                'description' => 'A long-lived thing Flint is tracking — strategic, thematic, or tactical',
                'hidden' => false,
            ],
        ];
    }
}
