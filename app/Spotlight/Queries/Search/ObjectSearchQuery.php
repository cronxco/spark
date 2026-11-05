<?php

namespace App\Spotlight\Queries\Search;

use App\Integrations\PluginRegistry;
use App\Models\EventObject;
use App\Services\Search\RecencyRanking;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class ObjectSearchQuery
{
    /**
     * Create Spotlight query for searching event objects.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::asDefault(function (string $query) {
            if (blank($query) || strlen($query) < 3) {
                return collect();
            }

            $objects = EventObject::query()
                ->where('user_id', Auth::id())
                ->where('title', 'ilike', "%{$query}%")
                ->limit(5);

            return app(RecencyRanking::class)->orderByText($objects, $query, 'title')
                ->get()
                ->map(function (EventObject $object, int $rank) {
                    // Build subtitle parts
                    $subtitleParts = [];
                    $objectIcon = 'cube';

                    // Get service from metadata if available
                    $service = $object->metadata['service'] ?? null;

                    if ($service) {
                        $pluginClass = PluginRegistry::getPlugin($service);
                        $serviceName = $pluginClass
                            ? $pluginClass::getDisplayName()
                            : Str::headline($service);
                        $subtitleParts[] = $serviceName;

                        // Get object type icon from plugin
                        if ($pluginClass && $object->type) {
                            $objectTypes = $pluginClass::getObjectTypes();
                            if (isset($objectTypes[$object->type]['icon'])) {
                                $objectIcon = normalize_icon_for_spotlight($objectTypes[$object->type]['icon']);
                            }
                        }
                    }

                    // Add concept/type
                    if ($object->concept) {
                        $subtitleParts[] = ucfirst($object->concept);
                    }

                    // Add time if available
                    if ($object->time) {
                        $dateFormat = $object->time->format('M j, Y');
                        $humanDate = $object->time->diffForHumans();
                        $subtitleParts[] = "{$dateFormat} ({$humanDate})";
                    }

                    $subtitle = implode(' • ', $subtitleParts);

                    return SpotlightResult::make()
                        ->setTitle($object->title ?? 'Untitled')
                        ->setSubtitle($subtitle)
                        ->setTypeahead('Object: ' . ($object->title ?? 'Untitled'))
                        ->setIcon($objectIcon)
                        ->setGroup('objects')
                        ->setPriority($rank + 1)
                        ->setAction('jump_to', ['path' => route('objects.show', $object)])
                        ->setTokens(['object' => $object]);
                });
        });
    }
}
