<?php

namespace App\Support;

use App\Integrations\PluginRegistry;
use App\Models\Event;
use Illuminate\Support\Str;

/**
 * Resolves entity reference IDs (currently event UUIDs from block
 * `referenced_event_ids` metadata) into compact reference dicts the mobile
 * client renders as tappable chips, and linkifies prose so the same
 * references appear inline.
 *
 * The shape `{type,id,title,service,domain}` mirrors what the web
 * `<x-event-ref>` component derives, so iOS chips match the web UI.
 */
class EntityReferenceResolver
{
    /**
     * Canonical universal-link host the iOS DeepLink parser recognises.
     */
    public const DEEP_LINK_HOST = 'https://spark.cronx.co';

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Resolve an array of event UUIDs into compact reference dicts,
     * preserving input order and skipping unknown/invalid IDs.
     *
     * @param  array<int, mixed>  $eventIds
     * @return array<int, array{type: string, id: string, title: string, service: string, domain: string}>
     */
    public static function resolveEvents(array $eventIds): array
    {
        $validIds = array_values(array_filter(
            $eventIds,
            fn ($id) => is_string($id) && preg_match(self::UUID_PATTERN, $id),
        ));

        if (empty($validIds)) {
            return [];
        }

        $events = Event::whereIn('id', $validIds)
            ->get(['id', 'service', 'domain', 'action'])
            ->keyBy('id');

        $references = [];

        foreach ($validIds as $id) {
            $event = $events->get($id);

            if (! $event) {
                continue;
            }

            $references[] = self::eventReference($event);
        }

        return $references;
    }

    /**
     * Wrap the first plain-text occurrence of each reference's title in the
     * prose as a markdown deep link, so the iOS markdown renderer can chip-ify
     * it. Idempotent and read-time: never touches text already inside a link,
     * and skips a reference if its title isn't found.
     *
     * @param  array<int, array{type: string, id: string, title: string, service: string, domain: string}>  $references
     */
    public static function linkify(?string $content, array $references): ?string
    {
        if ($content === null || $content === '' || empty($references)) {
            return $content;
        }

        foreach ($references as $reference) {
            $title = trim($reference['title'] ?? '');

            if ($title === '') {
                continue;
            }

            $url = self::deepLink($reference['type'], $reference['id']);

            if ($url === null) {
                continue;
            }

            // Match the title only when not already inside a [..](..) link.
            $pattern = '/(?<!\]\()(?<!\[)\b' . preg_quote($title, '/') . '\b(?![^\[]*\]\()/i';

            $content = preg_replace_callback(
                $pattern,
                fn (array $m) => '[' . $m[0] . '](' . $url . ')',
                $content,
                1,
            ) ?? $content;
        }

        return $content;
    }

    /**
     * @return array{type: string, id: string, title: string, service: string, domain: string}
     */
    private static function eventReference(Event $event): array
    {
        $pluginClass = PluginRegistry::getPlugin($event->service);

        $actionTypes = $pluginClass ? $pluginClass::getActionTypes() : [];
        $title = $actionTypes[$event->action]['display_name']
            ?? Str::headline($event->action ?: 'Event');

        $serviceName = $pluginClass
            ? $pluginClass::getDisplayName()
            : Str::headline($event->service ?: 'Unknown');

        $domain = $event->domain
            ?: ($pluginClass ? $pluginClass::getDomain() : 'knowledge');

        return [
            'type' => 'event',
            'id' => $event->id,
            'title' => $title,
            'service' => $serviceName,
            'domain' => $domain,
        ];
    }

    private static function deepLink(string $type, string $id): ?string
    {
        $path = match ($type) {
            'event' => 'event',
            'object' => 'object',
            'block' => 'block',
            'place' => 'place',
            'metric' => 'metric',
            default => null,
        };

        if ($path === null) {
            return null;
        }

        return self::DEEP_LINK_HOST . '/' . $path . '/' . $id;
    }
}
