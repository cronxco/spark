<?php

namespace App\Spotlight\Queries\Scoped;

use App\Integrations\PluginRegistry;
use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Support\Str;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class AccountEventsQuery
{
    /**
     * Create Spotlight query for listing recent events for a financial account.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('account', function (string $query, $accountToken) {
            $accountId = $accountToken->getParameter('id');
            if (! $accountId) {
                return collect();
            }

            $account = EventObject::find($accountId);
            if (! $account) {
                return collect();
            }

            // Get recent balance updates and transactions
            $events = Event::with(['integration'])
                ->where('actor_id', $accountId)
                ->when(! blank($query), function ($q) use ($query) {
                    $q->where('action', 'ilike', "%{$query}%");
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
                $actionIcon = 'currency-pound';

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
