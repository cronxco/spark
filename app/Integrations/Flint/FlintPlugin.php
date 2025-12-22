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
                'description' => 'Run agents every 4 hours for fresh insights',
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
                'description' => 'AI-generated daily digest of events and insights',
                'icon' => 'file-lines',
                'display_with_object' => false,
                'hidden' => false,
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
                'icon' => 'newspaper',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => false,
                'value_unit' => null,
            ],
            'flint_five_key_points' => [
                'display_name' => 'Key Points',
                'description' => 'Five most important points from the day',
                'icon' => 'list-bullet',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => true,
                'value_unit' => 'points',
            ],
            'flint_actions_required' => [
                'display_name' => 'Actions Required',
                'description' => 'AI-identified actions that need attention',
                'icon' => 'check-circle',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => true,
                'value_unit' => 'actions',
            ],
            'flint_things_to_be_aware_of' => [
                'display_name' => 'Awareness Alerts',
                'description' => 'Important items to be aware of',
                'icon' => 'exclamation-triangle',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => true,
                'value_unit' => 'alerts',
            ],
            'flint_insight' => [
                'display_name' => 'Daily Insight',
                'description' => 'AI-generated insight from daily data',
                'icon' => 'light-bulb',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => false,
                'value_unit' => null,
            ],
            'flint_suggestion' => [
                'display_name' => 'AI Suggestion',
                'description' => 'Intelligent suggestion based on patterns',
                'icon' => 'sparkles',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => false,
                'value_unit' => null,
            ],

            // Domain-Specific Insight Blocks
            'flint_health_insight' => [
                'display_name' => 'Health Insight',
                'description' => 'AI analysis of health and fitness data',
                'icon' => 'heart',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => true,
                'value_unit' => 'confidence',
                'value_multiplier' => 100,
            ],
            'flint_money_insight' => [
                'display_name' => 'Money Insight',
                'description' => 'AI analysis of financial data and spending',
                'icon' => 'currency-pound',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => true,
                'value_unit' => 'confidence',
                'value_multiplier' => 100,
            ],
            'flint_media_insight' => [
                'display_name' => 'Media Insight',
                'description' => 'AI analysis of media consumption patterns',
                'icon' => 'musical-note',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => true,
                'value_unit' => 'confidence',
                'value_multiplier' => 100,
            ],
            'flint_knowledge_insight' => [
                'display_name' => 'Knowledge Insight',
                'description' => 'AI analysis of learning and knowledge activities',
                'icon' => 'book-open',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => true,
                'value_unit' => 'confidence',
                'value_multiplier' => 100,
            ],
            'flint_online_insight' => [
                'display_name' => 'Online Insight',
                'description' => 'AI analysis of online activities and engagement',
                'icon' => 'globe-alt',
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
                'icon' => 'chart-bar',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => true,
                'value_unit' => 'confidence',
                'value_multiplier' => 100,
            ],
            'flint_correlation' => [
                'display_name' => 'Correlation',
                'description' => 'Statistical correlation between data points',
                'icon' => 'arrow-trending-up',
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
                'icon' => 'flag',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => true,
                'value_unit' => 'priority',
            ],
            'flint_urgent_alert' => [
                'display_name' => 'Urgent Alert',
                'description' => 'Time-sensitive alert requiring immediate attention',
                'icon' => 'bell-alert',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => false,
                'value_unit' => null,
            ],

            // Digest Block
            'flint_digest' => [
                'display_name' => 'Daily Digest',
                'description' => 'Comprehensive daily summary with insights and actions',
                'icon' => 'file-lines',
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
