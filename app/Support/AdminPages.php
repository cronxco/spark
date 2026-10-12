<?php

namespace App\Support;

/**
 * The admin pages, in the order the sidebar and Spotlight list them.
 */
class AdminPages
{
    /**
     * @return array<int, array{title: string, subtitle: string, icon: string, route: string, active: array<int, string>, god_mode_only: bool}>
     */
    public static function all(): array
    {
        $page = fn (string $title, string $subtitle, string $icon, string $route, array $active, bool $godModeOnly = false): array => [
            'title' => $title,
            'subtitle' => $subtitle,
            'icon' => $icon,
            'route' => $route,
            'active' => $active,
            'god_mode_only' => $godModeOnly,
        ];

        return [
            $page('Pulse', 'Data checks and one-off fixes', 'fas.heart-pulse', 'admin.pulse.index', ['admin.pulse.*']),
            $page('Data', 'Events, objects, blocks and relationships', 'fas.database', 'admin.events.index', ['admin.events.*', 'admin.objects.*', 'admin.blocks.*', 'admin.relationships.*']),
            $page('Pipeline', 'Task executions and failures', 'fas.diagram-project', 'admin.pipeline.index', ['admin.pipeline.*']),
            $page('Activity', 'System activity and changes', 'fas.history', 'admin.activity.index', ['admin.activity.*']),
            $page('Logs', 'Application logs', 'fas.file-lines', 'admin.logs.index', ['admin.logs.*']),
            $page('Bin', 'Deleted items', 'fas.trash', 'admin.bin.index', ['admin.bin.*']),
            $page('Duplicates', 'Find and merge duplicates', 'fas.copy', 'admin.duplicates.index', ['admin.duplicates.*']),
            $page('Search Analytics', 'Semantic search performance', 'fas.magnifying-glass-chart', 'admin.search.index', ['admin.search.*']),
            $page('Day Notes', 'Generate Outline day notes', 'fas.calendar-day', 'admin.daynotes.index', ['admin.daynotes.*']),
            $page('Push Notifications', 'Send a push notification', 'fas.bell', 'admin.push-notifications.index', ['admin.push-notifications.*']),
            $page('GoCardless', 'Agreements and requisitions (God Mode only)', 'fas.credit-card', 'admin.gocardless.index', ['admin.gocardless.*'], true),
            $page('God Mode', 'See everything across all users', 'fas.user-shield', 'admin.god-mode.index', ['admin.god-mode.*']),
        ];
    }

    /**
     * The pages the current admin can open right now.
     *
     * @return array<int, array{title: string, subtitle: string, icon: string, route: string, active: array<int, string>, god_mode_only: bool}>
     */
    public static function visible(): array
    {
        return array_values(array_filter(self::all(), fn (array $page): bool => ! $page['god_mode_only'] || AdminTenant::isGodMode()));
    }
}
