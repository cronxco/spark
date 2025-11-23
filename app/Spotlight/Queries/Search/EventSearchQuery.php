<?php

namespace App\Spotlight\Queries\Search;

use App\Integrations\PluginRegistry;
use App\Models\Event;
use Illuminate\Support\Str;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class EventSearchQuery
{
    /**
     * Create Spotlight query for searching events.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::asDefault(function (string $query) {
            if (blank($query) || strlen($query) < 3) {
                return collect();
            }

            return Event::with(['actor', 'target', 'integration'])
                ->where('action', 'ilike', "%{$query}%")
                ->latest('time')
                ->limit(5)
                ->get()
                ->map(function (Event $event) {
                    // Get formatted value
                    $formattedValue = format_event_value_display(
                        $event->formatted_value,
                        $event->value_unit,
                        $event->service ?? 'unknown',
                        $event->action
                    );

                    // Build subtitle parts
                    $subtitleParts = [];

                    // Get plugin and action type info
                    $pluginClass = null;
                    $actionIcon = 'list-bullet';

                    if ($event->service) {
                        $pluginClass = PluginRegistry::getPlugin($event->service);
                        $serviceName = $pluginClass
                            ? $pluginClass::getDisplayName()
                            : Str::headline($event->service);
                        $subtitleParts[] = $serviceName;

                        // Get action icon from plugin
                        if ($pluginClass && $event->action) {
                            $actionTypes = $pluginClass::getActionTypes();
                            if (isset($actionTypes[$event->action]['icon'])) {
                                $actionIcon = normalize_icon_for_spotlight($actionTypes[$event->action]['icon']);
                            }
                        }
                    }

                    // Add object title if action type has display_with_object = true
                    if ($event->service && $event->action) {
                        $shouldDisplayWithObject = should_display_action_with_object(
                            $event->action,
                            $event->service
                        );

                        if ($shouldDisplayWithObject) {
                            if ($event->actor) {
                                $subtitleParts[] = $event->actor->title;
                            } elseif ($event->target) {
                                $subtitleParts[] = $event->target->title;
                            }
                        }
                    }

                    // Add formatted value
                    if ($formattedValue) {
                        $subtitleParts[] = $formattedValue;
                    }

                    // Add date with human readable format
                    $dateFormat = $event->time->format('M j, g:ia');
                    $humanDate = $event->time->diffForHumans();
                    $subtitleParts[] = "{$dateFormat} ({$humanDate})";

                    $subtitle = implode(' • ', $subtitleParts);

                    // Boost priority for events from today or last week
                    $priority = $event->time->isToday() ? 1 :
                        ($event->time->isAfter(now()->subWeek()) ? 2 : 3);

                    return SpotlightResult::make()
                        ->setTitle(format_action_title($event->action))
                        ->setSubtitle($subtitle)
                        ->setTypeahead('Event: ' . $event->action . ' at ' . $event->time->format('M j, g:ia'))
                        ->setIcon($actionIcon)
                        ->setGroup('events')
                        ->setPriority($priority)
                        ->setAction('jump_to', ['path' => route('events.show', $event)])
                        ->setTokens(['event' => $event]);
                });
        });
    }
}
