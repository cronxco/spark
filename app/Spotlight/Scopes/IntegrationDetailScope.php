<?php

namespace App\Spotlight\Scopes;

use WireElements\Pro\Components\Spotlight\SpotlightScope;

class IntegrationDetailScope
{
    public static function make(): SpotlightScope
    {
        return SpotlightScope::forRoute('integrations.details', function ($scope, $request) {
            $integration = $request->route('integration');
            if ($integration) {
                $scope->applyToken('integration', [
                    'integration' => [
                        'id' => $integration->id,
                        'name' => $integration->name,
                    ],
                ]);
            }
        });
    }
}
