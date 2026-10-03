<?php

use App\Http\Controllers\Api\V1\CapturedBookmarksController as V1CapturedBookmarksController;
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
use App\Http\Controllers\Api\V1\Mobile\UpToSpeedUnmarkController as V1UpToSpeedUnmarkController;
use App\Http\Controllers\Auth\OAuthController;
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

/*
 * The pre-v1 routes (events, search, tokens, integrations, fetch/bookmarks,
 * assistant/context, flint/questions, task-executions and user) were retired
 * in favour of /api/v1. They answer 410 so an old caller learns where to go
 * instead of getting a bare 404.
 */
Route::any('{legacy}', fn () => response()->json([
    'message' => 'This endpoint has been retired. Use the /api/v1 equivalent.',
], 410))
    ->where('legacy', '(events|search|tokens|integrations|fetch|assistant|flint|task-executions|user)(/.*)?')
    ->name('api.legacy.gone');

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
            Route::post('knowledge/events/{id}/reprocess', [V1KnowledgeReprocessingController::class, 'store'])->middleware('if-match:event')->name('knowledge.events.reprocess');
            Route::post('{kind}/{id}/relationships', [V1EntityMutationsController::class, 'storeRelationship'])->whereIn('kind', ['events', 'objects', 'blocks'])->middleware('if-match:entity')->name('relationships.store');
            Route::delete('relationships/{relationship}', [V1EntityMutationsController::class, 'destroyRelationship'])->middleware('if-match:relationship')->name('relationships.destroy');
        });
        Route::post('bookmarks', [V1BookmarksController::class, 'store'])
            ->middleware('spark.ability:bookmark:write,data:write')
            ->name('bookmarks.store');
        Route::post('bookmarks/capture', [V1CapturedBookmarksController::class, 'store'])
            ->middleware('spark.ability:bookmark:write')
            ->name('bookmarks.capture');
        Route::post('integrations/sync', [V1IntegrationsController::class, 'syncService'])->middleware('spark.ability:integrations:sync')->name('integrations.sync-service');
        Route::post('integrations/{id}/sync', [V1IntegrationsController::class, 'sync'])->middleware('spark.ability:integrations:sync')->name('integrations.sync');
        Route::post('check-ins', [V1CheckInsController::class, 'store'])->middleware('spark.ability:insights:write')->name('check-ins.store');
        Route::post('anomalies/{id}/acknowledge', [V1AnomaliesController::class, 'acknowledge'])->middleware('spark.ability:insights:write')->name('anomalies.acknowledge');
        Route::post('up-to-speed/read', V1UpToSpeedReadController::class)->middleware('spark.ability:insights:write')->name('up-to-speed.read');
        Route::post('up-to-speed/unmark', V1UpToSpeedUnmarkController::class)->middleware('spark.ability:insights:write')->name('up-to-speed.unmark');
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
    ->middleware(['ios.enabled', 'sentry.mobile.logging', 'auth:sanctum', 'mobile.session', 'etag'])
    ->name('api.v1.mobile.')
    ->group(base_path('routes/mobile.php'));
