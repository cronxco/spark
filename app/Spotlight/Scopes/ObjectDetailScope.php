<?php

namespace App\Spotlight\Scopes;

use WireElements\Pro\Components\Spotlight\SpotlightScope;

class ObjectDetailScope
{
    public static function make(): SpotlightScope
    {
        return SpotlightScope::forRoute('objects.show', function ($scope, $request) {
            // Try multiple ways to get the object model
            $object = $request->route('object') ?? $request->route()->parameter('object');
            if ($object) {
                $scope->applyToken('object', [
                    'object' => [
                        'id' => $object->id,
                        'title' => $object->title ?? 'Untitled',
                        'type' => $object->type,
                        'concept' => $object->concept,
                    ],
                ]);
            }
        });
    }
}
