<?php

namespace App\Spotlight\Queries\Scoped;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
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

            return $objects->map(function ($object) use ($integration) {
                // Build subtitle
                $subtitleParts = [];

                // Add object type/concept info
                if ($object->concept) {
                    $subtitleParts[] = ucfirst($object->concept);
                }
                if ($object->type) {
                    $subtitleParts[] = Str::headline($object->type);
                }

                // Try to get latest event for this object
                $latestEvent = Event::where('actor_id', $object->id)
                    ->where('integration_id', $integration->id)
                    ->latest('time')
                    ->first();

                if ($latestEvent) {
                    $subtitleParts[] = 'Updated ' . $latestEvent->time->diffForHumans();
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
