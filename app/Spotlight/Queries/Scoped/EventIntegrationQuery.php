<?php

namespace App\Spotlight\Queries\Scoped;

use App\Models\Event;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class EventIntegrationQuery
{
    /**
     * Create Spotlight query for navigating to event's source integration.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('event', function (string $query, $eventToken) {
            $eventId = $eventToken->getParameter('id');
            if (! $eventId) {
                return collect();
            }

            $event = Event::with('integration')->find($eventId);
            if (! $event || ! $event->integration) {
                return collect();
            }

            return collect([
                SpotlightResult::make()
                    ->setTitle($event->integration->name)
                    ->setSubtitle('Source Integration • '.($event->service ? ucfirst($event->service) : 'Unknown Service'))
                    ->setIcon('link')
                    ->setGroup('integrations')
                    ->setPriority(1)
                    ->setAction('jump_to', ['path' => route('integrations.details', $event->integration)])
                    ->setTokens(['integration' => $event->integration]),
            ]);
        });
    }
}
