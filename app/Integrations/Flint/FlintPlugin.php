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
            // Legacy block types (kept for backward compatibility)
            'flint_summarised_headline' => [
                'display_name' => 'Daily Headline',
                'description' => 'AI-generated headline summarizing the day',
                'icon' => 'fas.newspaper',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => false,
                'value_unit' => null,
            ],
            'flint_five_key_points' => [
                'display_name' => 'Key Points',
                'description' => 'Five most important points from the day',
                'icon' => 'fas.list-ol',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => true,
                'value_unit' => 'points',
            ],
            'flint_actions_required' => [
                'display_name' => 'Actions Required',
                'description' => 'AI-identified actions that need attention',
                'icon' => 'fas.circle-check',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => true,
                'value_unit' => 'actions',
            ],
            'flint_things_to_be_aware_of' => [
                'display_name' => 'Awareness Alerts',
                'description' => 'Important items to be aware of',
                'icon' => 'fas.triangle-exclamation',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => true,
                'value_unit' => 'alerts',
            ],
            'flint_insight' => [
                'display_name' => 'Daily Insight',
                'description' => 'AI-generated insight from daily data',
                'icon' => 'fas.lightbulb',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => false,
                'value_unit' => null,
            ],
            'flint_suggestion' => [
                'display_name' => 'AI Suggestion',
                'description' => 'Intelligent suggestion based on patterns',
                'icon' => 'fas.hexagon-nodes-bolt',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => false,
                'value_unit' => null,
            ],

            // Domain-Specific Insight Blocks
            'flint_health_insight' => [
                'display_name' => 'Health Insight',
                'description' => 'AI analysis of health and fitness data',
                'icon' => 'fas.heart',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => true,
                'value_unit' => 'confidence',
                'value_multiplier' => 100,
            ],
            'flint_money_insight' => [
                'display_name' => 'Money Insight',
                'description' => 'AI analysis of financial data and spending',
                'icon' => 'fas.sterling-sign',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => true,
                'value_unit' => 'confidence',
                'value_multiplier' => 100,
            ],
            'flint_media_insight' => [
                'display_name' => 'Media Insight',
                'description' => 'AI analysis of media consumption patterns',
                'icon' => 'fas.play',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => true,
                'value_unit' => 'confidence',
                'value_multiplier' => 100,
            ],
            'flint_knowledge_insight' => [
                'display_name' => 'Knowledge Insight',
                'description' => 'AI analysis of learning and knowledge activities',
                'icon' => 'fas.book-open',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => true,
                'value_unit' => 'confidence',
                'value_multiplier' => 100,
            ],
            'flint_online_insight' => [
                'display_name' => 'Online Insight',
                'description' => 'AI analysis of online activities and engagement',
                'icon' => 'fab.internet-explorer',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => true,
                'value_unit' => 'confidence',
                'value_multiplier' => 100,
            ],

            // Cross-Domain and Pattern Blocks
            'flint_cross_domain_insight' => [
                'display_name' => 'Cross-Domain Insight',
                'description' => 'AI-discovered connections across different life domains',
                'icon' => 'fas.right-left',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => true,
                'value_unit' => 'confidence',
                'value_multiplier' => 100,
            ],
            'flint_pattern_detected' => [
                'display_name' => 'Pattern Detected',
                'description' => 'Recurring pattern identified by AI analysis',
                'icon' => 'fas.chart-simple',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => true,
                'value_unit' => 'confidence',
                'value_multiplier' => 100,
            ],
            'flint_correlation' => [
                'display_name' => 'Correlation',
                'description' => 'Statistical correlation between data points',
                'icon' => 'fas.arrow-trend-up',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => true,
                'value_unit' => 'strength',
                'value_multiplier' => 100,
            ],

            // Action and Suggestion Blocks
            'flint_prioritized_action' => [
                'display_name' => 'Prioritized Action',
                'description' => 'AI-prioritized action item requiring attention',
                'icon' => 'fas.flag',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => true,
                'value_unit' => 'priority',
            ],
            'flint_urgent_alert' => [
                'display_name' => 'Urgent Alert',
                'description' => 'Time-sensitive alert requiring immediate attention',
                'icon' => 'fas.bell',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => false,
                'value_unit' => null,
            ],

            // Digest Block
            'flint_digest' => [
                'display_name' => 'Daily Digest',
                'description' => 'Comprehensive daily summary with insights and actions',
                'icon' => 'fas.file-lines',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => true,
                'value_unit' => 'insights',
            ],
        ];
    }

    // No object types needed (assistant doesn't create objects)
    public static function getObjectTypes(): array
    {
        return [];
    }
}
