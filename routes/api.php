<?php

use App\Http\Controllers\Api\AssistantContextController;
use App\Http\Controllers\Api\FetchApiController;
use App\Http\Controllers\Api\FlintQuestionsController;
use App\Http\Controllers\Api\IntegrationApiController;
use App\Http\Controllers\Api\SearchApiController;
use App\Http\Controllers\Api\SemanticSearchController;
use App\Http\Controllers\Api\TaskExecutionController;
use App\Http\Controllers\Api\V1\Mobile\AnomaliesController as V1AnomaliesController;
use App\Http\Controllers\Api\V1\Mobile\BlocksController as V1BlocksController;
use App\Http\Controllers\Api\V1\Mobile\BookmarksController as V1BookmarksController;
use App\Http\Controllers\Api\V1\Mobile\BriefingController as V1BriefingController;
use App\Http\Controllers\Api\V1\Mobile\CheckInsController as V1CheckInsController;
use App\Http\Controllers\Api\V1\Mobile\EntityMutationsController as V1EntityMutationsController;
use App\Http\Controllers\Api\V1\Mobile\EventsController as V1EventsController;
use App\Http\Controllers\Api\V1\Mobile\FeedController as V1FeedController;
use App\Http\Controllers\Api\V1\Mobile\FlintDigestsController as V1FlintDigestsController;
use App\Http\Controllers\Api\V1\Mobile\HealthController as V1HealthController;
use App\Http\Controllers\Api\V1\Mobile\IntegrationsController as V1IntegrationsController;
use App\Http\Controllers\Api\V1\Mobile\KnowledgeReprocessingController as V1KnowledgeReprocessingController;
use App\Http\Controllers\Api\V1\Mobile\MapController as V1MapController;
use App\Http\Controllers\Api\V1\Mobile\MetricsController as V1MetricsController;
use App\Http\Controllers\Api\V1\Mobile\MoneyAccountsController as V1MoneyAccountsController;
use App\Http\Controllers\Api\V1\Mobile\ObjectsController as V1ObjectsController;
use App\Http\Controllers\Api\V1\Mobile\PlacesController as V1PlacesController;
use App\Http\Controllers\Api\V1\Mobile\SearchController as V1SearchController;
use App\Http\Controllers\Api\V1\Mobile\TagsController as V1TagsController;
use App\Http\Controllers\Api\V1\Mobile\UpToSpeedController as V1UpToSpeedController;
use App\Http\Controllers\Api\V1\Mobile\UpToSpeedReadController as V1UpToSpeedReadController;
use App\Http\Controllers\Auth\OAuthController;
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

        // Semantic Search API
        Route::post('search/events', [SearchApiController::class, 'searchEvents'])->name('api.search.events');
        Route::post('search/blocks', [SearchApiController::class, 'searchBlocks'])->name('api.search.blocks');
        Route::post('search/objects', [SearchApiController::class, 'searchObjects'])->name('api.search.objects');
        Route::post('search', [SearchApiController::class, 'searchAll'])->name('api.search.all');
        Route::post('search/semantic', [SemanticSearchController::class, 'search'])->name('api.search.semantic');

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
        Route::post('integrations/{integration}/trigger', [IntegrationApiController::class, 'trigger'])->name('api.integrations.trigger');
        Route::delete('integrations/{integration}', [IntegrationApiController::class, 'destroy'])->name('api.integrations.destroy');

        // Fetch API
        Route::post('fetch/bookmarks', [FetchApiController::class, 'bookmarkUrl'])
            ->middleware('ability:bookmark:write')
            ->name('api.fetch.bookmarks.store');

        // Assistant Context API
        Route::get('assistant/context', [AssistantContextController::class, 'index'])->name('api.assistant.context');

        // Flint Questions API
        Route::post('flint/questions/{block}/answer', [FlintQuestionsController::class, 'answer'])->name('api.flint.questions.answer');

        // Task Executions API
        Route::get('task-executions', [TaskExecutionController::class, 'index'])->name('api.task-executions.index');
        Route::get('task-executions/{taskExecution}', [TaskExecutionController::class, 'show'])->name('api.task-executions.show');

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

// OAuth PKCE token exchange + refresh (iOS companion app) — unauthenticated
Route::post('oauth/token', [OAuthController::class, 'token'])->middleware('throttle:oauth')->name('oauth.token');
Route::post('oauth/refresh', [OAuthController::class, 'refresh'])->middleware('throttle:oauth')->name('oauth.refresh');

/*
|--------------------------------------------------------------------------
| General API v1
|--------------------------------------------------------------------------
|
| This is the client-neutral counterpart to Spark MCP. It intentionally
| reuses the mobile controllers' tested query/command services, but does not
| inherit the iOS rollout flag or iOS-only token abilities.
|
*/
Route::prefix('v1')
    ->middleware(['auth:sanctum', 'etag'])
    ->name('api.v1.')
    ->group(function (): void {
        Route::middleware('spark.ability:data:read')->group(function (): void {
            Route::get('events', [V1FeedController::class, 'index'])->name('events.index');
            Route::get('events/{id}', [V1EventsController::class, 'show'])->name('events.show');
            Route::get('objects/{id}', [V1ObjectsController::class, 'show'])->name('objects.show');
            Route::get('blocks/{id}', [V1BlocksController::class, 'show'])->name('blocks.show');
            Route::get('search', [V1SearchController::class, 'index'])->name('search.index');
            Route::get('tags', [V1TagsController::class, 'index'])->name('tags.index');
            Route::get('tags/suggest', [V1TagsController::class, 'suggest'])->name('tags.suggest');
            Route::get('tags/{id}', [V1TagsController::class, 'show'])->whereNumber('id')->name('tags.show');
            Route::get('map/data', [V1MapController::class, 'data'])->name('map.data');
            Route::get('places/{id}', [V1PlacesController::class, 'show'])->name('places.show');
            Route::get('{kind}/{id}/relationships', [V1EntityMutationsController::class, 'relationships'])->whereIn('kind', ['events', 'objects', 'blocks'])->name('relationships.index');
        });

        Route::middleware('spark.ability:insights:read')->group(function (): void {
            Route::get('day-summary', [V1BriefingController::class, 'today'])->name('day-summary.show');
            Route::get('metrics', [V1MetricsController::class, 'index'])->name('metrics.index');
            Route::get('metrics/{metric}', [V1MetricsController::class, 'show'])->name('metrics.show');
            Route::get('check-ins', [V1CheckInsController::class, 'index'])->name('check-ins.index');
            Route::get('check-ins/history', [V1CheckInsController::class, 'history'])->name('check-ins.history');
            Route::get('up-to-speed', V1UpToSpeedController::class)->name('up-to-speed.index');
            Route::get('health/dashboard', [V1HealthController::class, 'dashboard'])->name('health.dashboard');
        });

        Route::middleware('spark.ability:integrations:read')->group(function (): void {
            Route::get('integrations', [V1IntegrationsController::class, 'index'])->name('integrations.index');
            Route::get('integrations/{id}', [V1IntegrationsController::class, 'show'])->name('integrations.show');
        });

        Route::middleware('spark.ability:flint:read')->group(function (): void {
            Route::get('flint/digests', [V1FlintDigestsController::class, 'index'])->name('flint.digests.index');
            Route::get('flint/digests/{id}', [V1FlintDigestsController::class, 'show'])->name('flint.digests.show');
        });

        Route::middleware('spark.ability:finance:read')->group(function (): void {
            Route::get('finance/accounts', [V1MoneyAccountsController::class, 'index'])->name('finance.accounts.index');
            Route::get('finance/accounts/{id}', [V1MoneyAccountsController::class, 'show'])->name('finance.accounts.show');
            Route::get('finance/accounts/{id}/balances', [V1MoneyAccountsController::class, 'balances'])->name('finance.accounts.balances');
        });

        Route::middleware('spark.ability:data:write')->group(function (): void {
            Route::patch('{kind}/{id}', [V1EntityMutationsController::class, 'update'])->whereIn('kind', ['events', 'objects', 'blocks'])->middleware('if-match:entity')->name('entities.update');
            Route::patch('events/{id}/note', [V1EventsController::class, 'updateNote'])->middleware('if-match:event')->name('events.note.update');
            Route::post('events/{id}/tags', [V1TagsController::class, 'storeEventTag'])->middleware('if-match:event')->name('events.tags.store');
            Route::delete('events/{id}/tags/{tagId}', [V1TagsController::class, 'destroyEventTag'])->whereNumber('tagId')->middleware('if-match:event')->name('events.tags.destroy');
            Route::post('objects/{id}/tags', [V1TagsController::class, 'storeObjectTag'])->middleware('if-match:object')->name('objects.tags.store');
            Route::delete('objects/{id}/tags/{tagId}', [V1TagsController::class, 'destroyObjectTag'])->whereNumber('tagId')->middleware('if-match:object')->name('objects.tags.destroy');
            Route::post('bookmarks', [V1BookmarksController::class, 'store'])->name('bookmarks.store');
            Route::post('knowledge/events/{id}/reprocess', [V1KnowledgeReprocessingController::class, 'store'])->middleware('if-match:event')->name('knowledge.events.reprocess');
            Route::post('{kind}/{id}/relationships', [V1EntityMutationsController::class, 'storeRelationship'])->whereIn('kind', ['events', 'objects', 'blocks'])->middleware('if-match:entity')->name('relationships.store');
            Route::delete('relationships/{relationship}', [V1EntityMutationsController::class, 'destroyRelationship'])->middleware('if-match:relationship')->name('relationships.destroy');
        });
        Route::post('integrations/sync', [V1IntegrationsController::class, 'syncService'])->middleware('spark.ability:integrations:sync')->name('integrations.sync-service');
        Route::post('integrations/{id}/sync', [V1IntegrationsController::class, 'sync'])->middleware('spark.ability:integrations:sync')->name('integrations.sync');
        Route::post('check-ins', [V1CheckInsController::class, 'store'])->middleware('spark.ability:insights:write')->name('check-ins.store');
        Route::post('anomalies/{id}/acknowledge', [V1AnomaliesController::class, 'acknowledge'])->middleware('spark.ability:insights:write')->name('anomalies.acknowledge');
        Route::post('up-to-speed/read', V1UpToSpeedReadController::class)->middleware('spark.ability:insights:write')->name('up-to-speed.read');
        Route::post('flint/questions/{block}/answer', [V1FlintDigestsController::class, 'answer'])->middleware('spark.ability:flint:write')->name('flint.questions.answer');
        Route::post('flint/digests', [V1FlintDigestsController::class, 'store'])->middleware('spark.ability:flint:write')->name('flint.digests.store');
        Route::post('finance/accounts', [V1MoneyAccountsController::class, 'store'])->middleware('spark.ability:finance:write')->name('finance.accounts.store');
        Route::patch('finance/accounts/{id}', [V1MoneyAccountsController::class, 'update'])->middleware(['spark.ability:finance:write', 'if-match:object'])->name('finance.accounts.update');
        Route::delete('finance/accounts/{id}', [V1MoneyAccountsController::class, 'destroy'])->middleware(['spark.ability:finance:write', 'if-match:object'])->name('finance.accounts.destroy');
        Route::post('finance/accounts/{id}/balances', [V1MoneyAccountsController::class, 'addBalance'])->middleware(['spark.ability:finance:write', 'if-match:object'])->name('finance.accounts.balances.store');
    });

// Mobile API surface — gated behind config('ios.mobile_api_enabled') so it's
// invisible in production until the iOS client is ready to ship. Default
// ability is `ios:read`; write-side endpoints override to `ios:write`.
Route::prefix('v1/mobile')
    ->middleware(['ios.enabled', 'sentry.mobile.logging', 'auth:sanctum', 'ability:ios:read', 'etag'])
    ->name('api.v1.mobile.')
    ->group(base_path('routes/mobile.php'));
