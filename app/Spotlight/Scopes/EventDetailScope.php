<?php

namespace App\Spotlight\Scopes;

use WireElements\Pro\Components\Spotlight\SpotlightScope;

class EventDetailScope
{
    public static function make(): SpotlightScope
    {
        return SpotlightScope::forRoute('events.show', function ($scope, $request) {
            // Try multiple ways to get the event model
            $event = $request->route('event') ?? $request->route()->parameter('event');

            if ($event) {
                $scope->applyToken('event', [
                    'event' => [
                        'id' => $event->id,
                        'display' => format_action_title($event->action).' • '.$event->time->format('M j, g:ia'),
                        'action' => $event->action,
                        'service' => $event->service,
                    ],
                ]);
            }
        });
    }
}
