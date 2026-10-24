<?php

namespace App\Services\Actions;

use App\Services\Actions\Handlers\AcknowledgeAnomaly;
use App\Services\Actions\Handlers\AcknowledgeMetricTrends;
use App\Services\Actions\Handlers\AddEventTag;
use App\Services\Actions\Handlers\CalculateMetricStatistics;
use App\Services\Actions\Handlers\DeleteEvent;
use App\Services\Actions\Handlers\DetectMetricTrends;
use App\Services\Actions\Handlers\ReprocessEvent;
use App\Services\Actions\Handlers\RunFlintRoutine;
use App\Services\Actions\Handlers\SetIntegrationPaused;
use App\Services\Actions\Handlers\SyncAllIntegrations;
use App\Services\Actions\Handlers\SyncIntegration;

/**
 * The launch set: navigation, safe idempotent actions, and data-changing
 * actions. Add a command here, never as a hard-coded Spotlight result.
 */
final class ActionCatalogue
{
    /** @return array<int, ActionDefinition> */
    public static function definitions(): array
    {
        return [
            ...self::navigation(),
            ...self::safe(),
            ...self::data(),
            ...self::pages(),
        ];
    }

    /** @return array<int, ActionDefinition> */
    private static function navigation(): array
    {
        $go = fn (string $id, string $title, string $icon, string $route, array $surfaces = ['web', 'native'], array $keywords = []) => new ActionDefinition(
            id: "nav.{$id}",
            title: $title,
            subtitle: 'Open',
            icon: $icon,
            domain: 'navigation',
            kind: ActionKind::Navigation,
            surfaces: $surfaces,
            destination: ['route' => $route],
            keywords: $keywords,
        );

        return [
            $go('today', 'Today', 'sun', 'dashboard'),
            $go('flint', 'Flint', 'sparkles', 'flint.index'),
            $go('metrics', 'Metrics', 'chart-bar', 'metrics.index', keywords: ['trends']),
            $go('money', 'Money', 'currency-pound', 'money', keywords: ['finance']),
            $go('tags', 'Tags', 'tag', 'tags.index'),
            $go('bookmarks', 'Bookmarks', 'bookmark', 'bookmarks'),
            $go('updates', 'Updates', 'arrow-path', 'updates.index', keywords: ['integrations']),
            $go('notifications', 'Notifications', 'bell', 'notifications.index', keywords: ['activity']),
            $go('activity', 'Recent activity', 'clock', 'admin.activity.index', ['web']),
        ];
    }

    /** @return array<int, ActionDefinition> */
    private static function safe(): array
    {
        $flint = fn (string $id, string $title, string $subtitle, string $icon, string $skill) => new ActionDefinition(
            id: "flint.run_{$id}",
            title: $title,
            subtitle: $subtitle,
            icon: $icon,
            domain: 'flint',
            kind: ActionKind::Safe,
            capability: 'flint:run',
            handler: RunFlintRoutine::class,
            params: ['skill' => $skill],
        );

        return [
            new ActionDefinition(
                id: 'integrations.sync_all',
                title: 'Sync all integrations',
                subtitle: 'Fetch the latest data',
                icon: 'arrow-path',
                domain: 'integrations',
                kind: ActionKind::Safe,
                capability: 'integrations:sync',
                handler: SyncAllIntegrations::class,
                keywords: ['update', 'refresh'],
            ),
            new ActionDefinition(
                id: 'integrations.sync',
                title: 'Sync integration',
                subtitle: 'Fetch the latest data',
                icon: 'arrow-path',
                domain: 'integrations',
                kind: ActionKind::Safe,
                capability: 'integrations:sync',
                handler: SyncIntegration::class,
                target: ['integration_id' => ['type' => 'uuid']],
                keywords: ['update', 'refresh', 'trigger'],
                contexts: ['integrations.details' => ['integration_id' => 'integration']],
            ),
            new ActionDefinition(
                id: 'metrics.calculate',
                title: 'Calculate statistics',
                subtitle: 'Recalculate every metric',
                icon: 'calculator',
                domain: 'insights',
                kind: ActionKind::Safe,
                capability: 'insights:write',
                handler: CalculateMetricStatistics::class,
                surfaces: ['web'],
                contexts: ['metrics.index' => [], 'metrics.show' => []],
            ),
            new ActionDefinition(
                id: 'metrics.detect_trends',
                title: 'Detect trends',
                subtitle: 'Check every metric',
                icon: 'chart-bar',
                domain: 'insights',
                kind: ActionKind::Safe,
                capability: 'insights:write',
                handler: DetectMetricTrends::class,
                surfaces: ['web'],
                contexts: ['metrics.index' => []],
            ),
            $flint('digest', 'Run Flint digest', "Generate today's digest", 'newspaper', 'spark-day-briefing-async'),
            $flint('topics', 'Run Flint topics', 'Review and update topics', 'map', 'flint-topics'),
            $flint('reading_list', 'Run Flint reading list', 'Pick what is worth reading', 'book-open', 'flint-reading-list'),
            $flint('news_roundup', 'Run Flint news roundup', 'Summarise overnight newsletters', 'rss', 'flint-news-roundup'),
        ];
    }

    /** @return array<int, ActionDefinition> */
    private static function data(): array
    {
        return [
            new ActionDefinition(
                id: 'events.tag_add',
                title: 'Tag event',
                subtitle: 'Add a tag',
                icon: 'tag',
                domain: 'events',
                kind: ActionKind::Data,
                confirm: ConfirmPolicy::Undo,
                capability: 'data:write',
                handler: AddEventTag::class,
                target: ['event_id' => ['type' => 'uuid'], 'name' => ['type' => 'string']],
                surfaces: ['native'],
            ),
            new ActionDefinition(
                id: 'events.delete',
                title: 'Delete event',
                subtitle: 'Move to the bin',
                icon: 'trash',
                domain: 'events',
                kind: ActionKind::Data,
                confirm: ConfirmPolicy::Undo,
                capability: 'data:write',
                handler: DeleteEvent::class,
                target: ['event_id' => ['type' => 'uuid']],
                surfaces: ['native'],
            ),
            new ActionDefinition(
                id: 'events.reprocess',
                title: 'Reprocess event',
                subtitle: 'Re-run enrichment',
                icon: 'cpu-chip',
                domain: 'events',
                kind: ActionKind::Data,
                confirm: ConfirmPolicy::Confirm,
                capability: 'data:write',
                handler: ReprocessEvent::class,
                target: ['event_id' => ['type' => 'uuid']],
                surfaces: ['native'],
            ),
            new ActionDefinition(
                id: 'integrations.pause',
                title: 'Pause integration',
                subtitle: 'Stop scheduled fetches',
                icon: 'pause',
                domain: 'integrations',
                kind: ActionKind::Data,
                confirm: ConfirmPolicy::Undo,
                capability: 'integrations:manage',
                handler: SetIntegrationPaused::class,
                target: ['integration_id' => ['type' => 'uuid']],
                params: ['paused' => true],
                contexts: ['integrations.details' => ['integration_id' => 'integration']],
            ),
            new ActionDefinition(
                id: 'integrations.resume',
                title: 'Resume integration',
                subtitle: 'Restart scheduled fetches',
                icon: 'play',
                domain: 'integrations',
                kind: ActionKind::Data,
                confirm: ConfirmPolicy::Undo,
                capability: 'integrations:manage',
                handler: SetIntegrationPaused::class,
                target: ['integration_id' => ['type' => 'uuid']],
                params: ['paused' => false],
                contexts: ['integrations.details' => ['integration_id' => 'integration']],
            ),
            new ActionDefinition(
                id: 'metrics.acknowledge_trends',
                title: 'Acknowledge trends',
                subtitle: 'Mark every trend seen',
                icon: 'check-circle',
                domain: 'insights',
                kind: ActionKind::Data,
                confirm: ConfirmPolicy::Undo,
                capability: 'insights:write',
                handler: AcknowledgeMetricTrends::class,
                target: ['metric_id' => ['type' => 'uuid']],
                surfaces: ['web'],
                contexts: ['metrics.show' => ['metric_id' => 'metric']],
            ),
            new ActionDefinition(
                id: 'insights.anomaly_acknowledge',
                title: 'Dismiss anomaly',
                subtitle: 'Not worth flagging',
                icon: 'check-circle',
                domain: 'insights',
                kind: ActionKind::Data,
                confirm: ConfirmPolicy::Undo,
                capability: 'insights:write',
                handler: AcknowledgeAnomaly::class,
                target: ['anomaly_id' => ['type' => 'string']],
                surfaces: ['native'],
            ),
        ];
    }

    /**
     * Commands that open a modal or jump on the page the user is already on.
     * The page owns the behaviour; the registry owns the copy and where each
     * one is offered.
     *
     * @return array<int, ActionDefinition>
     */
    private static function pages(): array
    {
        $open = fn (string $id, string $title, string $subtitle, string $icon, string $domain, string $event, string $route, array $keywords = []) => new ActionDefinition(
            id: $id,
            title: $title,
            subtitle: $subtitle,
            icon: $icon,
            domain: $domain,
            kind: ActionKind::Interface,
            surfaces: ['web'],
            keywords: $keywords,
            contexts: [$route => []],
            event: $event,
        );

        $relationships = fn (string $subject, string $noun, string $route) => [
            $open("{$subject}.open_relationships", 'Manage relationships', "Connect this {$noun} to other items", 'fas.right-left', 'events', 'open-manage-relationships-modal', $route, ['link', 'connect']),
            $open("{$subject}.open_add_relationship", 'Add relationship', "Connect this {$noun} to other items", 'fas.right-left', 'events', 'open-add-relationship-modal', $route, ['link', 'connect']),
        ];

        return [
            $open('money.open_add_balance', 'Add balance update', 'Record a balance by hand', 'plus-circle', 'finance', 'open-balance-modal', 'money.show', ['balance']),
            $open('money.open_edit', 'Edit account', 'Name and settings', 'pencil', 'finance', 'open-edit-modal', 'money.show'),
            $open('money.open_archive', 'Archive account', 'Hide this account', 'archive-box', 'finance', 'open-archive-modal', 'money.show'),
            $open('integrations.open_configure', 'Configure integration', 'Change settings', 'cog', 'integrations', 'open-configure-modal', 'integrations.details', ['settings']),
            $open('events.open_tag', 'Tag event', 'Add tags', 'tag', 'events', 'open-tag-modal', 'events.show'),
            $open('events.open_edit', 'Edit event', 'Change details', 'pencil', 'events', 'open-edit-event-modal', 'events.show'),
            ...$relationships('events', 'event', 'events.show'),
            $open('objects.open_tag', 'Tag object', 'Add tags', 'tag', 'events', 'open-tag-modal', 'objects.show'),
            $open('objects.show_timeline', 'View timeline', 'Every event for this object', 'clock', 'events', 'show-timeline', 'objects.show', ['events']),
            $open('objects.open_edit', 'Edit object', 'Change details', 'pencil', 'events', 'open-edit-object-modal', 'objects.show'),
            ...$relationships('objects', 'object', 'objects.show'),
            $open('blocks.view_parent', 'View parent event', 'Jump to its event', 'arrow-up-circle', 'events', 'jump-to-parent-event', 'blocks.show', ['event']),
            $open('blocks.open_edit', 'Edit block', 'Change details', 'pencil', 'events', 'open-edit-block-modal', 'blocks.show'),
            ...$relationships('blocks', 'block', 'blocks.show'),
        ];
    }
}
