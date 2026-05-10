<?php

use App\Http\Controllers\Api\V1\Mobile\AnomaliesController;
use App\Http\Controllers\Api\V1\Mobile\BlocksController;
use App\Http\Controllers\Api\V1\Mobile\BriefingController;
use App\Http\Controllers\Api\V1\Mobile\CheckInsController;
use App\Http\Controllers\Api\V1\Mobile\DevicesController;
use App\Http\Controllers\Api\V1\Mobile\EventsController;
use App\Http\Controllers\Api\V1\Mobile\FeedController;
use App\Http\Controllers\Api\V1\Mobile\FlintDigestsController;
use App\Http\Controllers\Api\V1\Mobile\HealthController;
use App\Http\Controllers\Api\V1\Mobile\IntegrationsController;
use App\Http\Controllers\Api\V1\Mobile\KnowledgeReprocessingController;
use App\Http\Controllers\Api\V1\Mobile\LiveActivitiesController;
use App\Http\Controllers\Api\V1\Mobile\MapController;
use App\Http\Controllers\Api\V1\Mobile\MeController;
use App\Http\Controllers\Api\V1\Mobile\MetricsController;
use App\Http\Controllers\Api\V1\Mobile\MoneyAccountsController;
use App\Http\Controllers\Api\V1\Mobile\NotificationsController;
use App\Http\Controllers\Api\V1\Mobile\NotificationSettingsController;
use App\Http\Controllers\Api\V1\Mobile\ObjectsController;
use App\Http\Controllers\Api\V1\Mobile\PingController;
use App\Http\Controllers\Api\V1\Mobile\PlacesController;
use App\Http\Controllers\Api\V1\Mobile\SearchController;
use App\Http\Controllers\Api\V1\Mobile\SyncController;
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

Route::get('settings/notifications', [NotificationSettingsController::class, 'show'])
    ->name('settings.notifications.show');

Route::get('briefing/today', [BriefingController::class, 'today'])->name('briefing.today');

Route::get('feed', [FeedController::class, 'index'])->name('feed.index');

Route::get('notifications', [NotificationsController::class, 'index'])->name('notifications.index');

Route::get('events/{id}', [EventsController::class, 'show'])->name('events.show');
Route::get('objects/{id}', [ObjectsController::class, 'show'])->name('objects.show');
Route::get('blocks/{id}', [BlocksController::class, 'show'])->name('blocks.show');
Route::get('metrics', [MetricsController::class, 'index'])->name('metrics.index');
Route::get('metrics/{metric}', [MetricsController::class, 'show'])->name('metrics.show');

Route::get('widgets/today', [WidgetsController::class, 'today'])->name('widgets.today');
Route::get('widgets/metrics/{metric}', [WidgetsController::class, 'metric'])->name('widgets.metric');
Route::get('widgets/spend', [WidgetsController::class, 'spend'])->name('widgets.spend');

Route::get('search', [SearchController::class, 'index'])->name('search.index');

Route::get('integrations', [IntegrationsController::class, 'index'])->name('integrations.index');
Route::get('integrations/{id}', [IntegrationsController::class, 'show'])->name('integrations.show');

Route::get('places/{id}', [PlacesController::class, 'show'])->name('places.show');

Route::get('map/data', [MapController::class, 'data'])->name('map.data');

Route::get('sync/delta', [SyncController::class, 'delta'])->name('sync.delta');

/*
|--------------------------------------------------------------------------
| Write-side endpoints
|--------------------------------------------------------------------------
|
| The parent group guards with `ability:ios:read`; each write route here
| stacks `ability:ios:write` so tokens missing the write scope are rejected.
|
*/

Route::post('devices', [DevicesController::class, 'register'])
    ->middleware('ability:ios:write')
    ->name('devices.register');

Route::delete('devices/{id}', [DevicesController::class, 'destroy'])
    ->middleware('ability:ios:write')
    ->name('devices.destroy');

Route::post('health/samples', [HealthController::class, 'samples'])
    ->middleware('ability:ios:write')
    ->name('health.samples');

Route::post('live-activities', [LiveActivitiesController::class, 'start'])
    ->middleware('ability:ios:write')
    ->name('live-activities.start');

Route::patch('live-activities/{id}', [LiveActivitiesController::class, 'update'])
    ->middleware('ability:ios:write')
    ->name('live-activities.update');

Route::delete('live-activities/{id}', [LiveActivitiesController::class, 'end'])
    ->middleware('ability:ios:write')
    ->name('live-activities.end');

Route::post('live-activities/{id}/tokens', [LiveActivitiesController::class, 'registerToken'])
    ->middleware('ability:ios:write')
    ->name('live-activities.tokens');

Route::get('check-ins', [CheckInsController::class, 'index'])
    ->name('check-ins.index');

Route::get('check-ins/history', [CheckInsController::class, 'history'])
    ->name('check-ins.history');

Route::post('check-ins', [CheckInsController::class, 'store'])
    ->middleware('ability:ios:write')
    ->name('check-ins.store');

Route::patch('settings/notifications', [NotificationSettingsController::class, 'update'])
    ->middleware('ability:ios:write')
    ->name('settings.notifications.update');

Route::post('anomalies/{id}/acknowledge', [AnomaliesController::class, 'acknowledge'])
    ->middleware('ability:ios:write')
    ->name('anomalies.acknowledge');

Route::post('notifications/{id}/read', [NotificationsController::class, 'markRead'])
    ->middleware('ability:ios:write')
    ->name('notifications.read');

Route::post('notifications/read-all', [NotificationsController::class, 'markAllRead'])
    ->middleware('ability:ios:write')
    ->name('notifications.read-all');

Route::delete('notifications/{id}', [NotificationsController::class, 'destroy'])
    ->middleware('ability:ios:write')
    ->name('notifications.destroy');
Route::post('knowledge/events/{id}/reprocess', [KnowledgeReprocessingController::class, 'store'])
    ->middleware('ability:ios:write')
    ->name('knowledge.events.reprocess');

/*
|--------------------------------------------------------------------------
| Flint digest endpoints
|--------------------------------------------------------------------------
*/

Route::get('flint/digests', [FlintDigestsController::class, 'index'])
    ->name('flint.digests.index');

Route::get('flint/digests/{id}', [FlintDigestsController::class, 'show'])
    ->name('flint.digests.show');

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
    ->middleware('ability:ios:write')
    ->name('money.accounts.update');

Route::delete('money/accounts/{id}', [MoneyAccountsController::class, 'destroy'])
    ->middleware('ability:ios:write')
    ->name('money.accounts.destroy');

Route::post('money/accounts/{id}/balances', [MoneyAccountsController::class, 'addBalance'])
    ->middleware('ability:ios:write')
    ->name('money.accounts.balances.store');
