<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class AasaController extends Controller
{
    public function show(): JsonResponse
    {
        $paths = array_map(
            fn (string $path): array => ['/' => $path, 'comment' => 'Universal Link'],
            config('ios.aasa_paths', [])
        );

        return response()->json([
            'applinks' => [
                'details' => [
                    [
                        'appIDs' => [config('ios.bundle_id')],
                        'components' => $paths,
                    ],
                ],
            ],
        ]);
    }
}
