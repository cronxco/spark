<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EventApiController;

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
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
