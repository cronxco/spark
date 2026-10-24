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
            'had_topic_task' => [
                'display_name' => 'Topic task',
                'description' => 'A dated task attached to a Flint Topic',
                'icon' => 'fas.list-check',
                'display_with_object' => false,
                'hidden' => true,
                'exclude_from_flint' => true,
                'supports_value' => false,
                'value_formatter' => null,
                'value_multiplier' => null,
                'value_unit' => null,
            ],
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
            // Task history written by TaskService::record() as 'task_' . $action,
            // plus the creation and idempotency events around it. All internal.
            'created_task' => [
                'display_name' => 'Created task',
                'description' => 'A task created in Knowledge',
                'icon' => 'fas.list-check',
                'display_with_object' => false,
                'hidden' => true,
                'exclude_from_flint' => true,
                'supports_value' => false,
                'value_formatter' => null,
                'value_multiplier' => null,
                'value_unit' => null,
            ],
            'task_mutation' => [
                'display_name' => 'Task mutation receipt',
                'description' => 'Idempotency receipt for a task change',
                'icon' => 'fas.list-check',
                'display_with_object' => false,
                'hidden' => true,
                'exclude_from_flint' => true,
                'supports_value' => false,
                'value_formatter' => null,
                'value_multiplier' => null,
                'value_unit' => null,
            ],
            'task_created' => [
                'display_name' => 'Task created',
                'description' => 'Task history: created',
                'icon' => 'fas.list-check',
                'display_with_object' => false,
                'hidden' => true,
                'exclude_from_flint' => true,
                'supports_value' => false,
                'value_formatter' => null,
                'value_multiplier' => null,
                'value_unit' => null,
            ],
            'task_edited' => [
                'display_name' => 'Task edited',
                'description' => 'Task history: edited',
                'icon' => 'fas.list-check',
                'display_with_object' => false,
                'hidden' => true,
                'exclude_from_flint' => true,
                'supports_value' => false,
                'value_formatter' => null,
                'value_multiplier' => null,
                'value_unit' => null,
            ],
            'task_complete' => [
                'display_name' => 'Task completed',
                'description' => 'Task history: completed',
                'icon' => 'fas.list-check',
                'display_with_object' => false,
                'hidden' => true,
                'exclude_from_flint' => true,
                'supports_value' => false,
                'value_formatter' => null,
                'value_multiplier' => null,
                'value_unit' => null,
            ],
            'task_reopen' => [
                'display_name' => 'Task reopened',
                'description' => 'Task history: reopened',
                'icon' => 'fas.list-check',
                'display_with_object' => false,
                'hidden' => true,
                'exclude_from_flint' => true,
                'supports_value' => false,
                'value_formatter' => null,
                'value_multiplier' => null,
                'value_unit' => null,
            ],
            'task_cancel' => [
                'display_name' => 'Task cancelled',
                'description' => 'Task history: cancelled',
                'icon' => 'fas.list-check',
                'display_with_object' => false,
                'hidden' => true,
                'exclude_from_flint' => true,
                'supports_value' => false,
                'value_formatter' => null,
                'value_multiplier' => null,
                'value_unit' => null,
            ],
            'task_reactivate' => [
                'display_name' => 'Task reactivated',
                'description' => 'Task history: reactivated',
                'icon' => 'fas.list-check',
                'display_with_object' => false,
                'hidden' => true,
                'exclude_from_flint' => true,
                'supports_value' => false,
                'value_formatter' => null,
                'value_multiplier' => null,
                'value_unit' => null,
            ],
            'task_delete' => [
                'display_name' => 'Task deleted',
                'description' => 'Task history: deleted',
                'icon' => 'fas.list-check',
                'display_with_object' => false,
                'hidden' => true,
                'exclude_from_flint' => true,
                'supports_value' => false,
                'value_formatter' => null,
                'value_multiplier' => null,
                'value_unit' => null,
            ],
            'task_restore' => [
                'display_name' => 'Task restored',
                'description' => 'Task history: restored',
                'icon' => 'fas.list-check',
                'display_with_object' => false,
                'hidden' => true,
                'exclude_from_flint' => true,
                'supports_value' => false,
                'value_formatter' => null,
                'value_multiplier' => null,
                'value_unit' => null,
            ],
            'task_retry_sync' => [
                'display_name' => 'Outline sync retried',
                'description' => 'Task history: Outline sync retried',
                'icon' => 'fas.list-check',
                'display_with_object' => false,
                'hidden' => true,
                'exclude_from_flint' => true,
                'supports_value' => false,
                'value_formatter' => null,
                'value_multiplier' => null,
                'value_unit' => null,
            ],
            'task_expired' => [
                'display_name' => 'Task expired',
                'description' => 'Task history: expired',
                'icon' => 'fas.list-check',
                'display_with_object' => false,
                'hidden' => true,
                'exclude_from_flint' => true,
                'supports_value' => false,
                'value_formatter' => null,
                'value_multiplier' => null,
                'value_unit' => null,
            ],
            'task_sync_blocked' => [
                'display_name' => 'Outline sync blocked',
                'description' => 'Task history: Outline sync blocked',
                'icon' => 'fas.list-check',
                'display_with_object' => false,
                'hidden' => true,
                'exclude_from_flint' => true,
                'supports_value' => false,
                'value_formatter' => null,
                'value_multiplier' => null,
                'value_unit' => null,
            ],
            'task_sync_conflict' => [
                'display_name' => 'Outline sync conflict',
                'description' => 'Task history: Outline sync conflict',
                'icon' => 'fas.list-check',
                'display_with_object' => false,
                'hidden' => true,
                'exclude_from_flint' => true,
                'supports_value' => false,
                'value_formatter' => null,
                'value_multiplier' => null,
                'value_unit' => null,
            ],
            'task_source_synced' => [
                'display_name' => 'Task synced from Outline',
                'description' => 'Task history: synced from Outline',
                'icon' => 'fas.list-check',
                'display_with_object' => false,
                'hidden' => true,
                'exclude_from_flint' => true,
                'supports_value' => false,
                'value_formatter' => null,
                'value_multiplier' => null,
                'value_unit' => null,
            ],
            'task_incident_reported' => [
                'display_name' => 'Task integration incident reported',
                'description' => 'Task history: integration incident reported',
                'icon' => 'fas.list-check',
                'display_with_object' => false,
                'hidden' => true,
                'exclude_from_flint' => true,
                'supports_value' => false,
                'value_formatter' => null,
                'value_multiplier' => null,
                'value_unit' => null,
            ],
            'task_incident_resolved' => [
                'display_name' => 'Task integration incident resolved',
                'description' => 'Task history: integration incident resolved',
                'icon' => 'fas.list-check',
                'display_with_object' => false,
                'hidden' => true,
                'exclude_from_flint' => true,
                'supports_value' => false,
                'value_formatter' => null,
                'value_multiplier' => null,
                'value_unit' => null,
            ],
            'task_project_removed' => [
                'display_name' => 'Task removed from its project',
                'description' => 'Task history: removed from its project',
                'icon' => 'fas.list-check',
                'display_with_object' => false,
                'hidden' => true,
                'exclude_from_flint' => true,
                'supports_value' => false,
                'value_formatter' => null,
                'value_multiplier' => null,
                'value_unit' => null,
            ],
        ];
    }

    public static function getBlockTypes(): array
    {
        return [
            'knowledge_task' => [
                'display_name' => 'Task', 'description' => 'A task managed in Knowledge',
                'icon' => 'fas.list-check', 'display_with_object' => false, 'hidden' => false,
                'supports_value' => false, 'value_unit' => null,
            ],
            'flint_topic_task' => [
                'display_name' => 'Topic Task',
                'description' => 'An actionable task with optional due and review dates',
                'icon' => 'fas.list-check',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => false,
                'value_unit' => null,
            ],
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
            'flint_health_insight' => [
                'display_name' => 'Health Insight',
                'description' => 'An insight about health or recovery, surfaced on the health dashboard',
                'icon' => 'fas.heart-pulse',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => false,
                'value_unit' => null,
            ],
            'flint_day_context' => [
                'display_name' => 'Day Context',
                'description' => "Today's calendar, birthdays and weather as structured data",
                'icon' => 'fas.calendar-day',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => false,
                'value_unit' => null,
            ],
            'flint_news' => [
                'display_name' => 'News Story',
                'description' => 'One story from the daily news roundup, with its sources',
                'icon' => 'fas.newspaper',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => false,
                'value_unit' => null,
            ],
            'flint_reading_pick' => [
                'display_name' => 'Reading Pick',
                'description' => 'Something from the backlog worth reading now, and why',
                'icon' => 'fas.book-open',
                'display_with_object' => false,
                'hidden' => false,
                'supports_value' => false,
                'value_unit' => null,
            ],
            'flint_reading_drop' => [
                'display_name' => 'Reading Drop',
                'description' => 'A saved item that has aged out and is worth deleting',
                'icon' => 'fas.trash-can',
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
            'user_profile' => [
                'icon' => 'fas.user',
                'display_name' => 'User',
                'description' => 'The user, as actor of digests, tasks and AI usage',
                'hidden' => true,
            ],
            // FlintDigestService::resolveDigestObject() names digest objects
            // '<period>_digest' or, for a routine with its own object, '<routine>_digest'.
            'morning_digest' => [
                'icon' => 'fas.file-lines',
                'display_name' => 'Morning Digest',
                'description' => 'A Flint digest',
                'hidden' => false,
            ],
            'afternoon_digest' => [
                'icon' => 'fas.file-lines',
                'display_name' => 'Afternoon Digest',
                'description' => 'A Flint digest',
                'hidden' => false,
            ],
            'evening_digest' => [
                'icon' => 'fas.file-lines',
                'display_name' => 'Evening Digest',
                'description' => 'A Flint digest',
                'hidden' => false,
            ],
            'news_roundup_digest' => [
                'icon' => 'fas.file-lines',
                'display_name' => 'News Roundup',
                'description' => 'A Flint digest',
                'hidden' => false,
            ],
            'reading_list_digest' => [
                'icon' => 'fas.file-lines',
                'display_name' => 'Reading List',
                'description' => 'A Flint digest',
                'hidden' => false,
            ],
            'money_weekly_digest' => [
                'icon' => 'fas.file-lines',
                'display_name' => 'Money Weekly',
                'description' => 'A Flint digest',
                'hidden' => false,
            ],
            'money_close_digest' => [
                'icon' => 'fas.file-lines',
                'display_name' => 'Money Close',
                'description' => 'A Flint digest',
                'hidden' => false,
            ],
        ];
    }

    /**
     * Retired names still present on old rows. Listed so the sense check
     * recognises them, and kept out of getBlockTypes()/getObjectTypes() so
     * the digest validator never accepts them for new writes.
     *
     * @return array<string, array<string, string>>
     */
    public static function getLegacyTypes(): array
    {
        return [
            'block_types' => [
                'flint_digest' => 'Digest blocks before Jan 2026',
                'flint_story' => 'News roundup story, superseded by flint_news',
                'flint_news_roundup_story' => 'News roundup story, superseded by flint_news',
                'flint_articles_waiting' => 'Digest block before Jan 2026',
                'flint_cross_domain_insight' => 'Insight block before Jan 2026, superseded by flint_insight',
                'flint_knowledge_insight' => 'Insight block before Jan 2026, superseded by flint_insight',
                'flint_money_insight' => 'Insight block before Jan 2026, superseded by flint_insight',
                'flint_pattern_detected' => 'Pattern block, last written Aug 2026',
                'flint_prioritized_action' => 'Digest block before Jan 2026',
                'flint_urgent_alert' => 'Digest block before Jan 2026',
            ],
            'object_types' => [
                'assistant' => 'Actor of had_analysis events, last written Aug 2026',
            ],
        ];
    }
}
