<?php

namespace App\Spotlight\Queries\Scoped;

use App\Models\Event;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class EventActorQuery
{
    /**
     * Create Spotlight query for navigating to event's actor object.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('event', function (string $query, $eventToken) {
            $eventId = $eventToken->getParameter('id');
            if (! $eventId) {
                return collect();
            }

            $event = Event::with('actor')->find($eventId);
            if (! $event || ! $event->actor) {
                return collect();
            }

            return collect([
                SpotlightResult::make()
                    ->setTitle($event->actor->title ?? 'Untitled Actor')
                    ->setSubtitle('Actor Object'.($event->actor->concept ? ' • '.ucfirst($event->actor->concept) : ''))
                    ->setIcon('user-circle')
                    ->setGroup('objects')
                    ->setPriority(1)
                    ->setAction('jump_to', ['path' => route('objects.show', $event->actor)])
                    ->setTokens(['object' => $event->actor]),
            ]);
        });
    }
}
