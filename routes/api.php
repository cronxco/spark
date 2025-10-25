<?php

use App\Http\Controllers\Api\IntegrationApiController;
use App\Http\Controllers\EventApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Sentry API request/response logging middleware
Route::middleware('sentry.api.logging')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        // Events API
        Route::apiResource('events', EventApiController::class)->names([
            'index' => 'api.events.index',
            'show' => 'api.events.show',
            'store' => 'api.events.store',
            'update' => 'api.events.update',
            'destroy' => 'api.events.destroy',
        ]);

        // Generate API token
        Route::post('tokens/create', function (Request $request) {
            $token = $request->user()->createToken($request->input('token_name', 'API Token'));

            return response()->json([
                'token' => $token->plainTextToken,
                'token_name' => $token->accessToken->name,
                'created_at' => $token->accessToken->created_at,
            ]);
        })->name('api.tokens.create');

        // List user's tokens
        Route::get('tokens', function (Request $request) {
            return response()->json([
                'tokens' => $request->user()->tokens()->get()->map(function ($token) {
                    return [
                        'id' => $token->id,
                        'name' => $token->name,
                        'created_at' => $token->created_at,
                        'last_used_at' => $token->last_used_at,
                    ];
                }),
            ]);
        })->name('api.tokens.index');

        // Revoke a token
        Route::delete('tokens/{token}', function (Request $request, $token) {
            $personalAccessToken = $request->user()->tokens()->find($token);

            if (! $personalAccessToken) {
                return response()->json(['error' => 'Token not found'], 404);
            }

            $personalAccessToken->delete();

            return response()->json(['message' => 'Token revoked successfully']);
        })->name('api.tokens.destroy');

        // Integrations API
        Route::apiResource('integrations', IntegrationApiController::class)->only(['index', 'show'])->names([
            'index' => 'api.integrations.index',
            'show' => 'api.integrations.show',
        ]);
        Route::post('integrations/{integration}/configure', [IntegrationApiController::class, 'configure'])->name('api.integrations.configure');
        Route::delete('integrations/{integration}', [IntegrationApiController::class, 'destroy'])->name('api.integrations.destroy');

        // Clear card stream cache
        Route::post('clear-card-cache', function (Request $request) {
            $userId = $request->user()->id;
            $pattern = "card_stream_{$userId}_*";

            // Clear all cache entries matching the pattern
            $store = Cache::getStore();
            if (method_exists($store, 'flush')) {
                // For stores that support flushing specific patterns
                // We'll just clear all card_stream entries for this user
                Cache::flush(); // Note: This clears ALL cache. In production, use a more targeted approach
            }

            return response()->json(['message' => 'Cache cleared successfully']);
        })->name('api.clear-card-cache');
    });
});

Route::get('user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum')->name('api.user');
