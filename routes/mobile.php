<?php

use App\Http\Controllers\Api\V1\Mobile\AnomaliesController;
use App\Http\Controllers\Api\V1\Mobile\BlocksController;
use App\Http\Controllers\Api\V1\Mobile\BookmarksController;
use App\Http\Controllers\Api\V1\Mobile\BriefingController;
use App\Http\Controllers\Api\V1\Mobile\CheckInsController;
use App\Http\Controllers\Api\V1\Mobile\ContextController;
use App\Http\Controllers\Api\V1\Mobile\DevicesController;
use App\Http\Controllers\Api\V1\Mobile\EntityMutationsController;
use App\Http\Controllers\Api\V1\Mobile\EventsController;
use App\Http\Controllers\Api\V1\Mobile\FeedController;
use App\Http\Controllers\Api\V1\Mobile\FlintDigestsController;
use App\Http\Controllers\Api\V1\Mobile\HealthController;
use App\Http\Controllers\Api\V1\Mobile\InsightDiscoveryController;
use App\Http\Controllers\Api\V1\Mobile\IntegrationsController;
use App\Http\Controllers\Api\V1\Mobile\KnowledgeReprocessingController;
use App\Http\Controllers\Api\V1\Mobile\LiveActivitiesController;
use App\Http\Controllers\Api\V1\Mobile\LocationsController;
use App\Http\Controllers\Api\V1\Mobile\MapController;
use App\Http\Controllers\Api\V1\Mobile\MeController;
use App\Http\Controllers\Api\V1\Mobile\MetricsController;
use App\Http\Controllers\Api\V1\Mobile\MoneyAccountsController;
use App\Http\Controllers\Api\V1\Mobile\NotificationPreferencesController;
use App\Http\Controllers\Api\V1\Mobile\NotificationsController;
use App\Http\Controllers\Api\V1\Mobile\NotificationSettingsController;
use App\Http\Controllers\Api\V1\Mobile\ObjectsController;
use App\Http\Controllers\Api\V1\Mobile\PingController;
use App\Http\Controllers\Api\V1\Mobile\PlacesController;
use App\Http\Controllers\Api\V1\Mobile\SearchController;
use App\Http\Controllers\Api\V1\Mobile\SyncController;
use App\Http\Controllers\Api\V1\Mobile\TagsController;
use App\Http\Controllers\Api\V1\Mobile\TypedSearchController;
use App\Http\Controllers\Api\V1\Mobile\UpToSpeedController;
use App\Http\Controllers\Api\V1\Mobile\UpToSpeedReadController;
use App\Http\Controllers\Api\V1\Mobile\WidgetsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API Routes
|--------------------------------------------------------------------------
|
| Mounted at /api/v1/mobile under the guard stack
|   [auth:sanctum, ability:ios:read, ios.enabled, etag]
| from routes/api.php. Write-side endpoints individually override the
| ability guard to `ability:ios:write`.
|
| Keep this file a pure route manifest — controllers carry the logic.
|
*/

Route::get('ping', PingController::class)->name('ping');

Route::get('me', MeController::class)->name('me');

Route::get('briefing/today', [BriefingController::class, 'today'])->name('briefing.today');

Route::get('health/dashboard', [HealthController::class, 'dashboard'])
    ->name('health.dashboard');

Route::get('feed', [FeedController::class, 'index'])->name('feed.index');
Route::get('events/filter', [FeedController::class, 'filter'])->name('events.filter');
Route::get('context/day', [ContextController::class, 'day'])->name('context.day');
Route::get('context/service-status', [ContextController::class, 'status'])->name('context.service-status');

Route::get('notifications', [NotificationsController::class, 'index'])->name('notifications.index');

Route::get('events/{id}', [EventsController::class, 'show'])->name('events.show');
Route::patch('{kind}/{id}/location', [LocationsController::class, 'set'])->whereIn('kind', ['events', 'objects'])->middleware(['ability:ios:write', 'if-match:entity'])->name('locations.set');
Route::delete('{kind}/{id}/location', [LocationsController::class, 'clear'])->whereIn('kind', ['events', 'objects'])->middleware(['ability:ios:write', 'if-match:entity'])->name('locations.clear');
Route::post('{kind}/{id}/location/geocode', [LocationsController::class, 'geocode'])->whereIn('kind', ['events', 'objects'])->middleware(['ability:ios:write', 'if-match:entity'])->name('locations.geocode');
Route::patch('{kind}/{id}', [EntityMutationsController::class, 'update'])
    ->whereIn('kind', ['events', 'objects', 'blocks'])
    ->middleware(['ability:ios:write', 'if-match:entity'])
    ->name('entities.update');
Route::patch('events/{id}/note', [EventsController::class, 'updateNote'])
    ->middleware(['ability:ios:write', 'if-match:event'])
    ->name('events.note.update');
Route::get('objects/{id}', [ObjectsController::class, 'show'])->name('objects.show');
Route::get('blocks/{id}', [BlocksController::class, 'show'])->name('blocks.show');
Route::get('metrics', [MetricsController::class, 'index'])->name('metrics.index');
Route::get('metrics/baselines', [InsightDiscoveryController::class, 'baselines'])->name('metrics.baselines');
Route::get('metrics/{metric}', [MetricsController::class, 'show'])->name('metrics.show');

Route::get('widgets/today', [WidgetsController::class, 'today'])->name('widgets.today');
Route::get('widgets/metrics/{metric}', [WidgetsController::class, 'metric'])->name('widgets.metric');
Route::get('widgets/spend', [WidgetsController::class, 'spend'])->name('widgets.spend');

Route::get('search', [SearchController::class, 'index'])->name('search.index');
Route::get('search/{type}', [TypedSearchController::class, 'index'])
    ->whereIn('type', ['events', 'objects', 'blocks'])
    ->name('search.typed');

Route::get('tags', [TagsController::class, 'index'])->name('tags.index');
Route::get('tags/suggest', [TagsController::class, 'suggest'])->name('tags.suggest');
Route::get('tags/{id}', [TagsController::class, 'show'])->whereNumber('id')->name('tags.show');
Route::post('events/{id}/tags', [TagsController::class, 'storeEventTag'])
    ->middleware(['ability:ios:write', 'if-match:event'])
    ->name('events.tags.store');
Route::delete('events/{id}/tags/{tagId}', [TagsController::class, 'destroyEventTag'])
    ->whereNumber('tagId')
    ->middleware(['ability:ios:write', 'if-match:event'])
    ->name('events.tags.destroy');
Route::post('objects/{id}/tags', [TagsController::class, 'storeObjectTag'])
    ->middleware(['ability:ios:write', 'if-match:object'])
    ->name('objects.tags.store');
Route::delete('objects/{id}/tags/{tagId}', [TagsController::class, 'destroyObjectTag'])
    ->whereNumber('tagId')
    ->middleware(['ability:ios:write', 'if-match:object'])
    ->name('objects.tags.destroy');

Route::get('integrations', [IntegrationsController::class, 'index'])->name('integrations.index');
Route::get('integrations/{id}', [IntegrationsController::class, 'show'])->name('integrations.show');
Route::post('integrations/{id}/sync', [IntegrationsController::class, 'sync'])
    ->middleware('ability:ios:write')
    ->name('integrations.sync');
Route::post('integrations/sync', [IntegrationsController::class, 'syncService'])
    ->middleware('ability:ios:write')
    ->name('integrations.sync-service');
Route::post('integrations/{id}/oauth/start', [IntegrationsController::class, 'oauthStart'])
    ->middleware('ability:ios:write')
    ->name('integrations.oauth.start');

Route::get('places/{id}', [PlacesController::class, 'show'])->name('places.show');

Route::get('map/data', [MapController::class, 'data'])->name('map.data');

Route::get('{kind}/{id}/relationships', [EntityMutationsController::class, 'relationships'])
    ->whereIn('kind', ['events', 'objects', 'blocks'])
    ->name('relationships.index');
Route::post('{kind}/{id}/relationships', [EntityMutationsController::class, 'storeRelationship'])
    ->whereIn('kind', ['events', 'objects', 'blocks'])
    ->middleware(['ability:ios:write', 'if-match:entity'])
    ->name('relationships.store');
Route::delete('relationships/{relationship}', [EntityMutationsController::class, 'destroyRelationship'])
    ->middleware(['ability:ios:write', 'if-match:relationship'])
    ->name('relationships.destroy');

Route::get('sync/delta', [SyncController::class, 'delta'])->name('sync.delta');

Route::get('settings/notifications', [NotificationPreferencesController::class, 'show'])
    ->name('settings.notifications.show');

/*
|--------------------------------------------------------------------------
| Write-side endpoints
|--------------------------------------------------------------------------
|
| The parent group guards with `ability:ios:read`; each write route here
| stacks `ability:ios:write` so tokens missing the write scope are rejected.
|
*/

// These iOS lifecycle endpoints are intentionally mobile-only. They are not
// mirrored through the general REST API or MCP.
Route::get('devices', [DevicesController::class, 'index'])->name('devices.index');
Route::post('devices', [DevicesController::class, 'register'])->middleware('ability:ios:write')->name('devices.register');
Route::post('devices/test', [DevicesController::class, 'test'])->middleware('ability:ios:write')->name('devices.test');
Route::delete('devices/{id}', [DevicesController::class, 'destroy'])->middleware('ability:ios:write')->name('devices.destroy');

Route::post('notifications/read-all', [NotificationsController::class, 'markAllRead'])
    ->middleware(['ability:ios:write', 'if-match:user'])
    ->name('notifications.read-all');

Route::post('notifications/{id}/read', [NotificationsController::class, 'markRead'])
    ->middleware(['ability:ios:write', 'if-match:notification'])
    ->name('notifications.read');

Route::delete('notifications/{id}', [NotificationsController::class, 'destroy'])
    ->middleware(['ability:ios:write', 'if-match:notification'])
    ->name('notifications.destroy');

Route::post('health/samples', [HealthController::class, 'samples'])
    ->middleware('ability:ios:write')
    ->name('health.samples');

Route::post('live-activities', [LiveActivitiesController::class, 'start'])->middleware('ability:ios:write')->name('live-activities.start');
Route::patch('live-activities/{id}', [LiveActivitiesController::class, 'update'])->middleware('ability:ios:write')->name('live-activities.update');
Route::delete('live-activities/{id}', [LiveActivitiesController::class, 'end'])->middleware('ability:ios:write')->name('live-activities.end');
Route::post('live-activities/{id}/tokens', [LiveActivitiesController::class, 'registerToken'])->middleware('ability:ios:write')->name('live-activities.tokens');

Route::get('check-ins', [CheckInsController::class, 'index'])
    ->name('check-ins.index');

Route::get('check-ins/history', [CheckInsController::class, 'history'])
    ->name('check-ins.history');

Route::get('check-ins/timezone', [CheckInsController::class, 'showTimezone'])
    ->name('check-ins.timezone.show');

Route::post('check-ins/timezone', [CheckInsController::class, 'storeTimezone'])
    ->middleware('ability:ios:write')
    ->name('check-ins.timezone.store');

Route::post('check-ins', [CheckInsController::class, 'store'])
    ->middleware('ability:ios:write')
    ->name('check-ins.store');

Route::post('check-ins/media', [CheckInsController::class, 'media'])
    ->middleware('ability:ios:write')
    ->name('check-ins.media');

// The read payload is owned by NotificationPreferences; this remains the
// established write handler for the iOS client.
Route::patch('settings/notifications', [NotificationSettingsController::class, 'update'])
    ->middleware(['ability:ios:write', 'if-match:user'])
    ->name('settings.notifications.update');

Route::post('anomalies/{id}/acknowledge', [AnomaliesController::class, 'acknowledge'])
    ->middleware('ability:ios:write')
    ->name('anomalies.acknowledge');

Route::post('knowledge/events/{id}/reprocess', [KnowledgeReprocessingController::class, 'store'])
    ->middleware('ability:ios:write')
    ->name('knowledge.events.reprocess');

Route::post('bookmarks', [BookmarksController::class, 'store'])
    ->middleware('ability:ios:write')
    ->name('bookmarks.store');

/*
|--------------------------------------------------------------------------
| API token management
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Up to Speed endpoints
|--------------------------------------------------------------------------
*/

Route::get('up-to-speed', UpToSpeedController::class)
    ->name('up-to-speed.index');

Route::post('up-to-speed/read', UpToSpeedReadController::class)
    ->middleware('ability:ios:write')
    ->name('up-to-speed.read');

/*
|--------------------------------------------------------------------------
| Flint digest endpoints
|--------------------------------------------------------------------------
*/

Route::get('flint/digests', [FlintDigestsController::class, 'index'])
    ->name('flint.digests.index');

Route::get('flint/digests/{id}', [FlintDigestsController::class, 'show'])
    ->name('flint.digests.show');

Route::post('flint/digests', [FlintDigestsController::class, 'store'])
    ->middleware('ability:ios:write')
    ->name('flint.digests.store');

Route::post('flint/questions/{block}/answer', [FlintDigestsController::class, 'answer'])
    ->middleware('ability:ios:write')
    ->name('flint.questions.answer');

/*
|--------------------------------------------------------------------------
| Money endpoints
|--------------------------------------------------------------------------
*/

Route::get('money/accounts', [MoneyAccountsController::class, 'index'])
    ->name('money.accounts.index');

Route::get('money/accounts/{id}', [MoneyAccountsController::class, 'show'])
    ->name('money.accounts.show');

Route::get('money/accounts/{id}/balances', [MoneyAccountsController::class, 'balances'])
    ->name('money.accounts.balances');

Route::post('money/accounts', [MoneyAccountsController::class, 'store'])
    ->middleware('ability:ios:write')
    ->name('money.accounts.store');

Route::patch('money/accounts/{id}', [MoneyAccountsController::class, 'update'])
    ->middleware(['ability:ios:write', 'if-match:object'])
    ->name('money.accounts.update');

Route::delete('money/accounts/{id}', [MoneyAccountsController::class, 'destroy'])
    ->middleware(['ability:ios:write', 'if-match:object'])
    ->name('money.accounts.destroy');

Route::post('money/accounts/{id}/balances', [MoneyAccountsController::class, 'addBalance'])
    ->middleware(['ability:ios:write', 'if-match:object'])
    ->name('money.accounts.balances.store');
