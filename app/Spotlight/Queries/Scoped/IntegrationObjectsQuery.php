<?php

namespace App\Spotlight\Queries\Scoped;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class IntegrationObjectsQuery
{
    /**
     * Create Spotlight query for listing objects/accounts created by an integration.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('integration', function (string $query, $integrationToken) {
            $integrationId = $integrationToken->getParameter('id');
            if (! $integrationId) {
                return collect();
            }

            $integration = Integration::find($integrationId);
            if (! $integration) {
                return collect();
            }

            // Find objects that have events from this integration
            $objectIds = Event::where('integration_id', $integrationId)
                ->whereNotNull('actor_id')
                ->distinct()
                ->pluck('actor_id');

            $objects = EventObject::whereIn('id', $objectIds)
                ->when(! blank($query), function ($q) use ($query) {
                    $q->where('title', 'ilike', "%{$query}%");
                })
                ->limit(10)
                ->get();

            if ($objects->isEmpty()) {
                return collect();
            }

            // Batch fetch latest events for all objects in a single query
            $objectIdsList = $objects->pluck('id');
            $latestEvents = Event::query()
                ->whereIn('actor_id', $objectIdsList)
                ->where('integration_id', $integrationId)
                ->whereIn('id', function ($subquery) use ($objectIdsList, $integrationId) {
                    $subquery->select(DB::raw('DISTINCT ON (actor_id) id'))
                        ->from('events')
                        ->whereIn('actor_id', $objectIdsList)
                        ->where('integration_id', $integrationId)
                        ->orderBy('actor_id')
                        ->orderByDesc('time');
                })
                ->get()
                ->keyBy('actor_id');

            return $objects->map(function ($object) use ($latestEvents) {
                // Build subtitle
                $subtitleParts = [];

                // Add object type/concept info
                if ($object->concept) {
                    $subtitleParts[] = ucfirst($object->concept);
                }
                if ($object->type) {
                    $subtitleParts[] = Str::headline($object->type);
                }

                // Get pre-fetched latest event for this object
                $latestEvent = $latestEvents->get($object->id);

                if ($latestEvent) {
                    $subtitleParts[] = 'Updated '.$latestEvent->time->diffForHumans();
                }

                $subtitle = implode(' • ', $subtitleParts);

                // Choose icon based on concept
                $icon = match ($object->concept) {
                    'account' => 'currency-pound',
                    'device' => 'device-phone-mobile',
                    'playlist' => 'musical-note',
                    'repository' => 'code-bracket',
                    default => 'cube',
                };

                return SpotlightResult::make()
                    ->setTitle($object->title ?? 'Untitled')
                    ->setSubtitle($subtitle)
                    ->setIcon($icon)
                    ->setGroup('objects')
                    ->setPriority(1)
                    ->setAction('jump_to', ['path' => route('objects.show', $object)])
                    ->setTokens(['object' => $object]);
            });
        });
    }
}
