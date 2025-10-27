<?php

namespace App\Spotlight\Queries\Scoped;

use App\Integrations\PluginRegistry;
use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Support\Str;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class ObjectIntegrationQuery
{
    /**
     * Create Spotlight query for navigating to object's source integration.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('object', function (string $query, $objectToken) {
            $objectId = $objectToken->getParameter('id');
            if (! $objectId) {
                return collect();
            }

            $object = EventObject::find($objectId);
            if (! $object) {
                return collect();
            }

            // Get service from metadata
            $service = $object->metadata['service'] ?? null;
            if (! $service) {
                return collect();
            }

            // Try to find the integration from the object's first event
            $integration = Event::where(function ($q) use ($objectId) {
                $q->where('actor_id', $objectId)
                    ->orWhere('target_id', $objectId);
            })
                ->with('integration')
                ->latest('time')
                ->first()
                ?->integration;

            if (! $integration) {
                return collect();
            }

            $pluginClass = PluginRegistry::getPlugin($service);
            $serviceName = $pluginClass
                ? $pluginClass::getDisplayName()
                : Str::headline($service);

            return collect([
                SpotlightResult::make()
                    ->setTitle($integration->name)
                    ->setSubtitle("Source Integration • {$serviceName}")
                    ->setIcon('link')
                    ->setGroup('integrations')
                    ->setPriority(1)
                    ->setAction('jump_to', ['path' => route('integrations.details', $integration)])
                    ->setTokens(['integration' => $integration]),
            ]);
        });
    }
}
