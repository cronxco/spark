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
            // Multi-Agent System Configuration
            'agents_enabled' => [
                'type' => 'boolean',
                'label' => 'Enable Multi-Agent System',
                'default' => true,
                'description' => 'Enable domain specialist agents for continuous analysis',
            ],
            'enabled_domains' => [
                'type' => 'array',
                'label' => 'Enabled Domains',
                'default' => ['health', 'money', 'media', 'knowledge', 'online'],
                'description' => 'Which domain agents should be active',
            ],
            'continuous_analysis_enabled' => [
                'type' => 'boolean',
                'label' => 'Enable Continuous Analysis',
                'default' => true,
                'description' => 'Run agents every 15 minutes for fresh insights',
            ],

            // Digest Schedule Configuration
            'use_schedule' => [
                'type' => 'boolean',
                'label' => 'Use Schedule',
                'default' => true,
                'description' => 'Generate digests at specific times',
            ],
            'schedule_times_weekday' => [
                'type' => 'array',
                'label' => 'Weekday Schedule Times',
                'default' => ['06:00', '18:00'],
                'description' => 'Times to generate digest on weekdays (HH:mm format)',
            ],
            'schedule_times_weekend' => [
                'type' => 'array',
                'label' => 'Weekend Schedule Times',
                'default' => ['08:00', '19:00'],
                'description' => 'Times to generate digest on weekends (HH:mm format)',
            ],
            'schedule_timezone' => [
                'type' => 'string',
                'label' => 'Schedule Timezone',
                'default' => 'UTC',
                'description' => 'Timezone for scheduled digest generation',
            ],

            // Agent Behavior Configuration
            'pattern_detection_enabled' => [
                'type' => 'boolean',
                'label' => 'Enable Pattern Detection',
                'default' => true,
                'description' => 'Run weekly pattern detection across domains',
            ],
            'cross_domain_synthesis_enabled' => [
                'type' => 'boolean',
                'label' => 'Enable Cross-Domain Synthesis',
                'default' => true,
                'description' => 'Find correlations across domains',
            ],
            'action_prioritization_enabled' => [
                'type' => 'boolean',
                'label' => 'Enable Action Prioritization',
                'default' => true,
                'description' => 'Prioritize suggested actions',
            ],

            // Legacy Configuration (kept for backward compatibility)
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
            'excluded_block_types' => [
                'type' => 'array',
                'label' => 'Excluded Block Types',
                'description' => 'Block types to exclude (leave empty to only exclude *_raw blocks)',
                'default' => [],
            ],
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
                'default' => null,
                'description' => 'Leave empty to use default from environment',
            ],
        ];
    }

    public static function getActionTypes(): array
    {
        return [
            'had_summary' => [
                'display_name' => 'Generated Digest',
                'display_name_past_tense' => 'Generated Digest',
                'icon' => 'document-text',
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
                'icon' => 'newspaper',
                'supports_value' => false,
            ],
            'flint_five_key_points' => [
                'display_name' => 'Key Points',
                'icon' => 'list-bullet',
                'supports_value' => true,
                'value_unit' => 'points',
            ],
            'flint_actions_required' => [
                'display_name' => 'Actions Required',
                'icon' => 'check-circle',
                'supports_value' => true,
                'value_unit' => 'actions',
            ],
            'flint_things_to_be_aware_of' => [
                'display_name' => 'Awareness Alerts',
                'icon' => 'exclamation-triangle',
                'supports_value' => true,
                'value_unit' => 'alerts',
            ],
            'flint_insight' => [
                'display_name' => 'Daily Insight',
                'icon' => 'light-bulb',
                'supports_value' => false,
            ],
            'flint_suggestion' => [
                'display_name' => 'AI Suggestion',
                'icon' => 'sparkles',
                'supports_value' => false,
            ],

            // Domain-Specific Insight Blocks
            'flint_health_insight' => [
                'display_name' => 'Health Insight',
                'icon' => 'heart',
                'supports_value' => true,
                'value_unit' => 'confidence',
                'value_multiplier' => 100,
            ],
            'flint_money_insight' => [
                'display_name' => 'Money Insight',
                'icon' => 'currency-pound',
                'supports_value' => true,
                'value_unit' => 'confidence',
                'value_multiplier' => 100,
            ],
            'flint_media_insight' => [
                'display_name' => 'Media Insight',
                'icon' => 'musical-note',
                'supports_value' => true,
                'value_unit' => 'confidence',
                'value_multiplier' => 100,
            ],
            'flint_knowledge_insight' => [
                'display_name' => 'Knowledge Insight',
                'icon' => 'book-open',
                'supports_value' => true,
                'value_unit' => 'confidence',
                'value_multiplier' => 100,
            ],
            'flint_online_insight' => [
                'display_name' => 'Online Insight',
                'icon' => 'globe-alt',
                'supports_value' => true,
                'value_unit' => 'confidence',
                'value_multiplier' => 100,
            ],

            // Cross-Domain and Pattern Blocks
            'flint_cross_domain_insight' => [
                'display_name' => 'Cross-Domain Insight',
                'icon' => 'arrows-right-left',
                'supports_value' => true,
                'value_unit' => 'confidence',
                'value_multiplier' => 100,
            ],
            'flint_pattern_detected' => [
                'display_name' => 'Pattern Detected',
                'icon' => 'chart-bar',
                'supports_value' => true,
                'value_unit' => 'confidence',
                'value_multiplier' => 100,
            ],
            'flint_correlation' => [
                'display_name' => 'Correlation',
                'icon' => 'arrow-trending-up',
                'supports_value' => true,
                'value_unit' => 'strength',
                'value_multiplier' => 100,
            ],

            // Action and Suggestion Blocks
            'flint_prioritized_action' => [
                'display_name' => 'Prioritized Action',
                'icon' => 'flag',
                'supports_value' => true,
                'value_unit' => 'priority',
            ],
            'flint_urgent_alert' => [
                'display_name' => 'Urgent Alert',
                'icon' => 'bell-alert',
                'supports_value' => false,
            ],

            // Digest Block
            'flint_digest' => [
                'display_name' => 'Daily Digest',
                'icon' => 'document-text',
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
