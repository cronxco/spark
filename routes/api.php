<?php

use App\Http\Controllers\EventApiController;
use App\Http\Controllers\IntegrationController;
use Illuminate\Http\Request;
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

Route::middleware('auth:sanctum')->group(function () {
    // Events API
    Route::get('/events', [EventApiController::class, 'index']);
    Route::get('/events/{event}', [EventApiController::class, 'show']);
    Route::post('/events', [EventApiController::class, 'store']);
    Route::put('/events/{event}', [EventApiController::class, 'update']);
    Route::delete('/events/{event}', [EventApiController::class, 'destroy']);

    // Generate API token
    Route::post('/tokens/create', function (Request $request) {
        $token = $request->user()->createToken($request->input('token_name', 'API Token'));

        return response()->json([
            'token' => $token->plainTextToken,
            'token_name' => $token->accessToken->name,
            'created_at' => $token->accessToken->created_at,
        ]);
    });

    // List user's tokens
    Route::get('/tokens', function (Request $request) {
        return response()->json([
            'tokens' => $request->user()->tokens()->get()->map(function ($token) {
                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'created_at' => $token->created_at,
                    'last_used_at' => $token->last_used_at,
                ];
            })
        ]);
    });

    // Revoke a token
    Route::delete('/tokens/{token}', function (Request $request, $tokenId) {
        $token = $request->user()->tokens()->find($tokenId);

        if (!$token) {
            return response()->json(['error' => 'Token not found'], 404);
        }

        $token->delete();

        return response()->json(['message' => 'Token revoked successfully']);
    });

    // Integrations API
    Route::get('/integrations', function (Request $request) {
        $plugins = \App\Integrations\PluginRegistry::getAllPlugins()->map(function ($pluginClass) {
            return [
                'identifier' => $pluginClass::getIdentifier(),
                'name' => $pluginClass::getDisplayName(),
                'description' => $pluginClass::getDescription(),
                'type' => $pluginClass::getServiceType(),
                'configuration_schema' => $pluginClass::getConfigurationSchema(),
            ];
        });
        
        $userIntegrations = $request->user()->integrations()->get();
        
        return response()->json([
            'plugins' => $plugins,
            'integrations' => $userIntegrations,
        ]);
    });

    Route::get('/integrations/{integration}', function (Request $request, $integrationId) {
        $integration = $request->user()->integrations()->findOrFail($integrationId);
        
        return response()->json($integration);
    });

    Route::post('/integrations/{integration}/configure', function (Request $request, $integrationId) {
        $integration = $request->user()->integrations()->findOrFail($integrationId);
        
        $pluginClass = \App\Integrations\PluginRegistry::getPlugin($integration->service);
        if (!$pluginClass) {
            return response()->json(['error' => 'Plugin not found'], 404);
        }
        
        $schema = $pluginClass::getConfigurationSchema();
        
        // Build validation rules
        $rules = [];
        foreach ($schema as $field => $config) {
            $fieldRules = [];
            
            if ($config['required'] ?? false) {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'nullable';
            }
            
            switch ($config['type']) {
                case 'array':
                    $fieldRules[] = 'array';
                    break;
                case 'string':
                    $fieldRules[] = 'string';
                    break;
                case 'integer':
                    $fieldRules[] = 'integer';
                    break;
            }
            
            $rules[$field] = $fieldRules;
        }
        
        $validated = $request->validate($rules);
        
        // Process array fields that come as comma-separated strings
        foreach ($validated as $field => $value) {
            if ($schema[$field]['type'] === 'array' && is_string($value)) {
                $validated[$field] = array_filter(array_map('trim', explode(',', $value)));
            }
        }
        
        $integration->update([
            'configuration' => $validated,
        ]);
        
        return response()->json([
            'message' => 'Integration configured successfully',
            'integration' => $integration->fresh(),
        ]);
    });

    Route::delete('/integrations/{integration}', function (Request $request, $integrationId) {
        $integration = $request->user()->integrations()->findOrFail($integrationId);
        
        $integration->delete();
        
        return response()->json(['message' => 'Integration deleted successfully']);
    });
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
