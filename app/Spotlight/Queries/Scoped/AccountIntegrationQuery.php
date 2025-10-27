<?php

namespace App\Spotlight\Queries\Scoped;

use App\Integrations\PluginRegistry;
use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Support\Str;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class AccountIntegrationQuery
{
    /**
     * Create Spotlight query for navigating to the source integration of a financial account.
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

            // Find the integration that created this account by looking at recent events
            $event = Event::with('integration')
                ->where('actor_id', $accountId)
                ->whereNotNull('integration_id')
                ->latest('time')
                ->first();

            if (! $event || ! $event->integration) {
                return collect();
            }

            $integration = $event->integration;

            // Get plugin info for icon and display name
            $pluginClass = PluginRegistry::getPlugin($integration->service);
            $serviceName = $pluginClass
                ? $pluginClass::getDisplayName()
                : Str::headline($integration->service);

            $icon = 'link';
            if ($pluginClass) {
                $pluginIcon = $pluginClass::getIcon();
                if ($pluginIcon) {
                    $icon = normalize_icon_for_spotlight($pluginIcon);
                }
            }

            return collect([
                SpotlightResult::make()
                    ->setTitle($integration->name)
                    ->setSubtitle("Source Integration • {$serviceName}")
                    ->setIcon($icon)
                    ->setGroup('integrations')
                    ->setPriority(1)
                    ->setAction('jump_to', ['path' => route('integrations.details', $integration)])
                    ->setTokens(['integration' => $integration]),
            ]);
        });
    }
}
