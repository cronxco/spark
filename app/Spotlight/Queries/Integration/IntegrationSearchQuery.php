<?php

namespace App\Spotlight\Queries\Integration;

use App\Integrations\PluginRegistry;
use App\Models\Integration;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class IntegrationSearchQuery
{
    /**
     * Create Spotlight query for searching integrations (mode-specific).
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forMode('integrations', function (string $query) {
            $integrationsQuery = Integration::with('group');

            if (! blank($query)) {
                $integrationsQuery->where(function ($q) use ($query) {
                    $q->where('name', 'ilike', "%{$query}%")
                        ->orWhereHas('group', function ($gq) use ($query) {
                            $gq->where('service', 'ilike', "%{$query}%");
                        });
                });
            }

            return $integrationsQuery
                ->limit(5)
                ->get()
                ->map(function (Integration $integration) {
                    $plugin = PluginRegistry::getPluginInstance($integration->group->service);
                    if (! $plugin) {
                        return null;
                    }

                    $subtitle = $plugin::getDisplayName();

                    if ($integration->last_successful_update_at) {
                        $subtitle .= ' • Updated '.$integration->last_successful_update_at->diffForHumans();
                    } elseif ($integration->last_triggered_at) {
                        $subtitle .= ' • Processing...';
                    } else {
                        $subtitle .= ' • Never updated';
                    }

                    // Show if integration is paused
                    $config = $integration->configuration ?? [];
                    if ($config['paused'] ?? false) {
                        $subtitle .= ' • Paused';
                    }

                    return SpotlightResult::make()
                        ->setTitle($integration->name)
                        ->setSubtitle($subtitle)
                        ->setTypeahead('Integration: '.$integration->name)
                        ->setIcon(normalize_icon_for_spotlight($plugin::getIcon()))
                        ->setGroup('integrations')
                        ->setPriority(1)
                        ->setAction('jump_to', ['path' => route('integrations.details', $integration)]);
                })
                ->filter();
        });
    }
}
