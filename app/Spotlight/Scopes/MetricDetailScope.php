<?php

namespace App\Spotlight\Scopes;

use WireElements\Pro\Components\Spotlight\SpotlightScope;

class MetricDetailScope
{
    public static function make(): SpotlightScope
    {
        return SpotlightScope::forRoute('metrics.show', function ($scope, $request) {
            $metric = $request->route('metric');
            if ($metric) {
                $scope->applyToken('metric', [
                    'metric' => [
                        'id' => $metric->id,
                        'name' => $metric->getDisplayName(),
                    ],
                ]);
            }
        });
    }
}
