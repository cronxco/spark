<?php

namespace App\Services\Actions;

use App\Services\Actions\Handlers\AcknowledgeAnomaly;
use App\Services\Actions\Handlers\AddEventTag;
use App\Services\Actions\Handlers\DeleteEvent;
use App\Services\Actions\Handlers\LogReflection;
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
        ];
    }

    /** @return array<int, ActionDefinition> */
    private static function navigation(): array
    {
        $go = fn (string $id, string $title, string $icon, ?string $route, ?string $native, array $keywords = []) => new ActionDefinition(
            id: "nav.{$id}",
            title: $title,
            subtitle: 'Open',
            icon: $icon,
            domain: 'navigation',
            kind: ActionKind::Navigation,
            surfaces: array_values(array_filter([$route !== null ? 'web' : null, $native !== null ? 'native' : null])),
            destination: array_filter(['route' => $route, 'native' => $native]),
            keywords: $keywords,
        );

        return [
            $go('today', 'Today', 'sun', 'dashboard', 'day', ['day']),
            $go('flint', 'Flint', 'sparkles', 'flint.index', 'flint'),
            $go('knowledge', 'Knowledge', 'book-open', 'knowledge.tasks', 'knowledge', ['tasks', 'projects']),
            $go('health', 'Health', 'heart', null, 'explore.health', ['sleep', 'activity']),
            $go('metrics', 'Metrics', 'chart-bar', 'metrics.index', 'explore.metrics', ['trends']),
            $go('money', 'Money', 'currency-pound', 'money', 'explore.money', ['finance']),
            $go('tags', 'Tags', 'tag', 'tags.index', 'explore.tags'),
            $go('map', 'Map', 'map-pin', 'map.index', 'explore.map', ['places']),
            $go('bookmarks', 'Bookmarks', 'bookmark', 'bookmarks', null),
            $go('updates', 'Updates', 'arrow-path', 'updates.index', null, ['integrations']),
            $go('notifications', 'Notifications', 'bell', 'notifications.index', null, ['activity']),
            $go('activity', 'Recent activity', 'clock', 'admin.activity.index', null),
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
                keywords: ['update', 'refresh'],
                verbs: ['sync', 'refresh', 'update'],
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
                verbs: ['tag'],
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
                verbs: ['delete', 'remove'],
            ),
            // Internal: `POST /log` writes the reflection and records the receipt
            // that carries its Undo, so no surface lists or runs this.
            new ActionDefinition(
                id: 'events.log_reflection',
                title: 'Log reflection',
                subtitle: 'Write a line down',
                icon: 'pencil',
                domain: 'events',
                kind: ActionKind::Data,
                confirm: ConfirmPolicy::Undo,
                capability: 'data:write',
                handler: LogReflection::class,
                surfaces: [],
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
                verbs: ['reprocess'],
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
                verbs: ['pause'],
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
                verbs: ['resume', 'unpause'],
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
}
