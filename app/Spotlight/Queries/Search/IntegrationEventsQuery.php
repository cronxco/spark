<?php

namespace App\Spotlight\Queries\Search;

use App\Models\Event;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class IntegrationEventsQuery
{
    /**
     * Query for events when an integration token is active.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('integration', function (string $query, $integrationToken) {
            $integrationId = $integrationToken->getParameter('id');

            $eventsQuery = Event::where('integration_id', $integrationId);

            if (! blank($query)) {
                $eventsQuery->where('action', 'ilike', "%{$query}%");
            }

            return $eventsQuery
                ->latest('time')
                ->limit(5)
                ->get()
                ->map(function (Event $event) {
                    $formattedValue = format_event_value_display(
                        $event->value,
                        $event->value_unit,
                        $event->service ?? 'unknown',
                        $event->action
                    );

                    return SpotlightResult::make()
                        ->setTitle(ucfirst(str_replace('_', ' ', $event->action)))
                        ->setSubtitle($formattedValue . ' • ' . $event->time->diffForHumans())
                        ->setIcon('list-bullet')
                        ->setGroup('events')
                        ->setPriority($event->time->isToday() ? 1 : 2)
                        ->setAction('jump_to', ['path' => route('events.show', $event)]);
                });
        });
    }
}
