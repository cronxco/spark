<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class AasaController extends Controller
{
    public function show(): JsonResponse
    {
        $teamId = config('ios.apple_team_id');
        $bundleId = config('ios.app_bundle_id');

        if (empty($teamId)) {
            abort(500, 'Apple Team ID is not configured');
        }

        $appIdentifier = "{$teamId}.{$bundleId}";

        $paths = config('ios.aasa_paths', []);

        return response()->json([
            'applinks' => [
                'details' => [
                    [
                        'appID' => $appIdentifier,
                        'paths' => $paths,
                    ],
                ],
            ],
            'webcredentials' => [
                'apps' => [$appIdentifier],
            ],
        ]);
    }
}
