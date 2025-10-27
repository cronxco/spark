<?php

namespace App\Spotlight\Queries\Scoped;

use App\Integrations\PluginRegistry;
use App\Models\Event;
use App\Models\MetricStatistic;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class MetricEventsQuery
{
    /**
     * Create Spotlight query for listing recent events matching a metric's service/action.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('metric', function (string $query, $metricToken) {
            $metricId = $metricToken->getParameter('id');
            if (! $metricId) {
                return collect();
            }

            $metric = MetricStatistic::find($metricId);
            if (! $metric) {
                return collect();
            }

            // Find events matching this metric's service and action
            $events = Event::with(['integration', 'actor', 'target'])
                ->where('service', $metric->service)
                ->where('action', $metric->action)
                ->when(! blank($query), function ($q) use ($query) {
                    $q->where(function ($subQ) use ($query) {
                        $subQ->whereHas('actor', function ($actorQ) use ($query) {
                            $actorQ->where('title', 'ilike', "%{$query}%");
                        })
                            ->orWhereHas('target', function ($targetQ) use ($query) {
                                $targetQ->where('title', 'ilike', "%{$query}%");
                            });
                    });
                })
                ->latest('time')
                ->limit(10)
                ->get();

            if ($events->isEmpty()) {
                return collect();
            }

            return $events->map(function ($event) {
                // Get formatted value
                $formattedValue = format_event_value_display(
                    $event->formatted_value,
                    $event->value_unit,
                    $event->service ?? 'unknown',
                    $event->action
                );

                // Build subtitle parts
                $subtitleParts = [];
                $actionIcon = 'list-bullet';

                if ($event->service) {
                    $pluginClass = PluginRegistry::getPlugin($event->service);

                    // Get action icon from plugin
                    if ($pluginClass && $event->action) {
                        $actionTypes = $pluginClass::getActionTypes();
                        if (isset($actionTypes[$event->action]['icon'])) {
                            $actionIcon = normalize_icon_for_spotlight($actionTypes[$event->action]['icon']);
                        }
                    }
                }

                // Add actor/target context if present
                if ($event->actor && $event->actor->title) {
                    $subtitleParts[] = $event->actor->title;
                }

                // Add formatted value
                if ($formattedValue) {
                    $subtitleParts[] = $formattedValue;
                }

                // Add date
                $dateFormat = $event->time->format('M j, g:ia');
                $humanDate = $event->time->diffForHumans();
                $subtitleParts[] = "{$dateFormat} ({$humanDate})";

                $subtitle = implode(' • ', $subtitleParts);

                // Boost priority for recent events
                $priority = $event->time->isToday() ? 1 :
                    ($event->time->isAfter(now()->subWeek()) ? 2 : 3);

                return SpotlightResult::make()
                    ->setTitle(format_action_title($event->action))
                    ->setSubtitle($subtitle)
                    ->setIcon($actionIcon)
                    ->setGroup('events')
                    ->setPriority($priority)
                    ->setAction('jump_to', ['path' => route('events.show', $event)])
                    ->setTokens(['event' => $event]);
            });
        });
    }
}
