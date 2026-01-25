<?php

namespace App\Spotlight\Queries\Scoped;

use App\Models\Event;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class EventTargetQuery
{
    /**
     * Create Spotlight query for navigating to event's target object.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('event', function (string $query, $eventToken) {
            $eventId = $eventToken->getParameter('id');
            if (! $eventId) {
                return collect();
            }

            $event = Event::with('target')->find($eventId);
            if (! $event || ! $event->target) {
                return collect();
            }

            return collect([
                SpotlightResult::make()
                    ->setTitle($event->target->title ?? 'Untitled Target')
                    ->setSubtitle('Target Object' . ($event->target->concept ? ' • ' . ucfirst($event->target->concept) : ''))
                    ->setIcon('flag')
                    ->setGroup('objects')
                    ->setPriority(1)
                    ->setAction('jump_to', ['path' => route('objects.show', $event->target)])
                    ->setTokens(['object' => $event->target]),
            ]);
        });
    }
}
