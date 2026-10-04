<?php

use App\Http\Controllers\Api\V1\CapturedBookmarksController;
use App\Http\Controllers\Api\V1\Mobile\AnomaliesController;
use App\Http\Controllers\Api\V1\Mobile\ApiTokensController;
use App\Http\Controllers\Api\V1\Mobile\BlocksController;
use App\Http\Controllers\Api\V1\Mobile\BookmarksController;
use App\Http\Controllers\Api\V1\Mobile\BriefingController;
use App\Http\Controllers\Api\V1\Mobile\CapturesController;
use App\Http\Controllers\Api\V1\Mobile\CheckInsController;
use App\Http\Controllers\Api\V1\Mobile\ContextController;
use App\Http\Controllers\Api\V1\Mobile\DevicesController;
use App\Http\Controllers\Api\V1\Mobile\EntityMutationsController;
use App\Http\Controllers\Api\V1\Mobile\EventsController;
use App\Http\Controllers\Api\V1\Mobile\FeedController;
use App\Http\Controllers\Api\V1\Mobile\FlintDigestsController;
use App\Http\Controllers\Api\V1\Mobile\FlintNotesController;
use App\Http\Controllers\Api\V1\Mobile\FlintQuestionsController;
use App\Http\Controllers\Api\V1\Mobile\FlintReviewController;
use App\Http\Controllers\Api\V1\Mobile\FlintRoutineHealthController;
use App\Http\Controllers\Api\V1\Mobile\FlintTopicsController;
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
use App\Http\Controllers\Api\V1\Mobile\NetWorthController;
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
use App\Http\Controllers\Api\V1\Mobile\UpToSpeedUnmarkController;
use App\Http\Controllers\Api\V1\Mobile\WidgetsController;
use App\Http\Controllers\Auth\OAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API Routes
|--------------------------------------------------------------------------
|
| Mounted at /api/v1/mobile under the guard stack
|   [ios.enabled, auth:sanctum, mobile.session, etag]
| from routes/api.php. Each route names the one action-scoped capability it
| needs (`spark.ability:*`, decision D-API-3). The app's session holds
| SparkAbility::MOBILE_SESSION; the old ios:read/ios:write scopes open nothing.
|
| Keep this file a pure route manifest — controllers carry the logic.
|
*/

Route::get('ping', PingController::class)->name('ping');

Route::get('me', MeController::class)->name('me');

/*
 * Ends the calling session server-side. Needs no capability, so a read-only
 * session can still sign itself out, and deliberately not behind
 * if-match: signing out must never be blocked by a precondition.
 */
Route::post('logout', [OAuthController::class, 'logout'])->name('logout');

Route::get('briefing/today', [BriefingController::class, 'today'])->middleware('spark.ability:insights:read')->name('briefing.today');

Route::get('health/dashboard', [HealthController::class, 'dashboard'])
    ->middleware('spark.ability:insights:read')
    ->name('health.dashboard');

Route::get('feed', [FeedController::class, 'index'])->middleware('spark.ability:data:read')->name('feed.index');
Route::get('events/filter', [FeedController::class, 'filter'])->middleware('spark.ability:data:read')->name('events.filter');
Route::get('context/day', [ContextController::class, 'day'])->middleware('spark.ability:data:read')->name('context.day');
Route::get('context/service-status', [ContextController::class, 'status'])->middleware('spark.ability:data:read')->name('context.service-status');

Route::get('notifications', [NotificationsController::class, 'index'])->middleware('spark.ability:notifications:read')->name('notifications.index');
Route::get('notifications/feed', [NotificationsController::class, 'feed'])->middleware('spark.ability:notifications:read')->name('notifications.feed');
Route::get('notifications/feed/{id}', [NotificationsController::class, 'show'])
    ->where('id', 'activity:[0-9]+|[0-9a-fA-F-]{36}')
    ->middleware('spark.ability:notifications:read')
    ->name('notifications.show');

Route::get('events/{id}', [EventsController::class, 'show'])->middleware('spark.ability:data:read')->name('events.show');
Route::get('events/{id}/route', [EventsController::class, 'route'])
    ->middleware('spark.ability:data:read')
    ->name('events.route');
Route::patch('{kind}/{id}/location', [LocationsController::class, 'set'])->whereIn('kind', ['events', 'objects'])->middleware(['spark.ability:data:write', 'if-match:entity'])->name('locations.set');
Route::delete('{kind}/{id}/location', [LocationsController::class, 'clear'])->whereIn('kind', ['events', 'objects'])->middleware(['spark.ability:data:write', 'if-match:entity'])->name('locations.clear');
Route::post('{kind}/{id}/location/geocode', [LocationsController::class, 'geocode'])->whereIn('kind', ['events', 'objects'])->middleware(['spark.ability:data:write', 'if-match:entity'])->name('locations.geocode');
Route::patch('{kind}/{id}', [EntityMutationsController::class, 'update'])
    ->whereIn('kind', ['events', 'objects', 'blocks'])
    ->middleware(['spark.ability:data:write', 'if-match:entity'])
    ->name('entities.update');
Route::patch('events/{id}/note', [EventsController::class, 'updateNote'])
    ->middleware(['spark.ability:data:write', 'if-match:event'])
    ->name('events.note.update');
Route::get('objects/{id}', [ObjectsController::class, 'show'])->middleware('spark.ability:data:read')->name('objects.show');

/*
 * Soft delete takes the entity's If-Match like every destructive write.
 * Restore is the client's Undo: the deleted row has no readable version, and
 * restoring twice changes nothing, so it carries no precondition.
 */
Route::delete('events/{id}', [EventsController::class, 'destroy'])
    ->middleware(['spark.ability:data:write', 'if-match:event'])
    ->name('events.destroy');
Route::post('events/{id}/restore', [EventsController::class, 'restore'])
    ->middleware('spark.ability:data:write')
    ->name('events.restore');
Route::delete('objects/{id}', [ObjectsController::class, 'destroy'])
    ->middleware(['spark.ability:data:write', 'if-match:object'])
    ->name('objects.destroy');
Route::post('objects/{id}/restore', [ObjectsController::class, 'restore'])
    ->middleware('spark.ability:data:write')
    ->name('objects.restore');
Route::get('blocks/{id}', [BlocksController::class, 'show'])->middleware('spark.ability:data:read')->name('blocks.show');
Route::get('metrics', [MetricsController::class, 'index'])->middleware('spark.ability:insights:read')->name('metrics.index');
Route::get('metrics/baselines', [InsightDiscoveryController::class, 'baselines'])->middleware('spark.ability:insights:read')->name('metrics.baselines');
Route::get('metrics/{metric}', [MetricsController::class, 'show'])->middleware('spark.ability:insights:read')->name('metrics.show');


Route::get('widgets/today', [WidgetsController::class, 'today'])->middleware('spark.ability:insights:read')->name('widgets.today');
Route::get('widgets/metrics/{metric}', [WidgetsController::class, 'metric'])->middleware('spark.ability:insights:read')->name('widgets.metric');
Route::get('widgets/spend', [WidgetsController::class, 'spend'])->middleware('spark.ability:finance:read')->name('widgets.spend');

Route::get('search', [SearchController::class, 'index'])->middleware('spark.ability:data:read')->name('search.index');
Route::get('search/{type}', [TypedSearchController::class, 'index'])
    ->whereIn('type', ['events', 'objects', 'blocks'])
    ->middleware('spark.ability:data:read')
    ->name('search.typed');

Route::get('tags', [TagsController::class, 'index'])->middleware('spark.ability:data:read')->name('tags.index');
Route::get('tags/suggest', [TagsController::class, 'suggest'])->middleware('spark.ability:data:read')->name('tags.suggest');
Route::get('tags/{id}', [TagsController::class, 'show'])->whereNumber('id')->middleware('spark.ability:data:read')->name('tags.show');
Route::post('events/{id}/tags', [TagsController::class, 'storeEventTag'])
    ->middleware(['spark.ability:data:write', 'if-match:event'])
    ->name('events.tags.store');
Route::delete('events/{id}/tags/{tagId}', [TagsController::class, 'destroyEventTag'])
    ->whereNumber('tagId')
    ->middleware(['spark.ability:data:write', 'if-match:event'])
    ->name('events.tags.destroy');
Route::post('objects/{id}/tags', [TagsController::class, 'storeObjectTag'])
    ->middleware(['spark.ability:data:write', 'if-match:object'])
    ->name('objects.tags.store');
Route::delete('objects/{id}/tags/{tagId}', [TagsController::class, 'destroyObjectTag'])
    ->whereNumber('tagId')
    ->middleware(['spark.ability:data:write', 'if-match:object'])
    ->name('objects.tags.destroy');

Route::get('integrations', [IntegrationsController::class, 'index'])->middleware('spark.ability:integrations:read')->name('integrations.index');
Route::get('integrations/{id}', [IntegrationsController::class, 'show'])->middleware('spark.ability:integrations:read')->name('integrations.show');
Route::post('integrations/{id}/sync', [IntegrationsController::class, 'sync'])
    ->middleware(['spark.ability:integrations:sync', 'if-match:integration'])
    ->name('integrations.sync');
Route::post('integrations/{id}/pause', [IntegrationsController::class, 'setPaused'])
    ->middleware(['spark.ability:integrations:manage', 'if-match:integration'])
    ->name('integrations.pause');
Route::post('integrations/sync', [IntegrationsController::class, 'syncService'])
    ->middleware('spark.ability:integrations:sync')
    ->name('integrations.sync-service');
Route::post('integrations/{id}/oauth/start', [IntegrationsController::class, 'oauthStart'])
    ->middleware('spark.ability:integrations:manage')
    ->name('integrations.oauth.start');

Route::get('places/{id}', [PlacesController::class, 'show'])->middleware('spark.ability:data:read')->name('places.show');

Route::get('map/data', [MapController::class, 'data'])->middleware('spark.ability:data:read')->name('map.data');

Route::get('relationship-types', [EntityMutationsController::class, 'relationshipTypes'])
    ->name('relationship-types.index');
Route::get('{kind}/{id}/relationships', [EntityMutationsController::class, 'relationships'])
    ->whereIn('kind', ['events', 'objects', 'blocks'])
    ->middleware('spark.ability:data:read')
    ->name('relationships.index');
Route::post('{kind}/{id}/relationships', [EntityMutationsController::class, 'storeRelationship'])
    ->whereIn('kind', ['events', 'objects', 'blocks'])
    ->middleware(['spark.ability:data:write', 'if-match:entity'])
    ->name('relationships.store');
Route::delete('relationships/{relationship}', [EntityMutationsController::class, 'destroyRelationship'])
    ->middleware(['spark.ability:data:write', 'if-match:relationship'])
    ->name('relationships.destroy');

Route::get('sync/delta', [SyncController::class, 'delta'])->middleware('spark.ability:data:read')->name('sync.delta');

Route::get('settings/notifications', [NotificationPreferencesController::class, 'show'])
    ->middleware('spark.ability:notifications:read')
    ->name('settings.notifications.show');

/*
|--------------------------------------------------------------------------
| Write-side endpoints
|--------------------------------------------------------------------------
|
| Each write route names its write capability, so a read-only session is
| rejected with 403.
|
*/

// These iOS lifecycle endpoints are intentionally mobile-only. They are not
// mirrored through the general REST API or MCP.
Route::get('devices', [DevicesController::class, 'index'])->middleware('spark.ability:notifications:read')->name('devices.index');
Route::post('devices', [DevicesController::class, 'register'])->middleware('spark.ability:notifications:write')->name('devices.register');
Route::post('devices/test', [DevicesController::class, 'test'])->middleware('spark.ability:notifications:write')->name('devices.test');
Route::delete('devices/{id}', [DevicesController::class, 'destroy'])->middleware('spark.ability:notifications:write')->name('devices.destroy');

/*
 * Marking read is an idempotent state transition — replaying it cannot lose an
 * update — so it carries no If-Match precondition. It previously required one,
 * which returned 428 for every shipped client: `GET /notifications` exposes no
 * per-notification version and there is no `GET /notifications/{id}`, so no
 * client could obtain the strong ETag the middleware demanded.
 *
 * Archiving is the same: it is reversible from History, so it carries no
 * precondition either. It briefly required one, which the iOS client (by
 * design) never sends, so every native archive answered 428 and the row came
 * back.
 *
 * Deletion is destructive and keeps its precondition; CompactNotificationResource
 * now emits `version` so a client can satisfy it.
 */
Route::post('notifications/read-all', [NotificationsController::class, 'markAllRead'])
    ->middleware('spark.ability:notifications:write')
    ->name('notifications.read-all');

// Delivery receipts from the app (decision N-8): id, event, time and action
// identifier only, never message content.
Route::post('notifications/receipts', [NotificationsController::class, 'recordReceipts'])
    ->middleware('spark.ability:notifications:write')
    ->name('notifications.receipts');

Route::post('notifications/{id}/receipts', [NotificationsController::class, 'recordReceipt'])
    ->middleware('spark.ability:notifications:write')
    ->name('notifications.receipt');

Route::post('notifications/{id}/read', [NotificationsController::class, 'markRead'])
    ->middleware('spark.ability:notifications:write')
    ->name('notifications.read');

Route::post('notifications/{id}/unread', [NotificationsController::class, 'markUnread'])
    ->middleware('spark.ability:notifications:write')
    ->name('notifications.unread');

Route::post('notifications/{id}/archive', [NotificationsController::class, 'archive'])
    ->middleware('spark.ability:notifications:write')
    ->name('notifications.archive');

Route::delete('notifications/{id}', [NotificationsController::class, 'destroy'])
    ->middleware(['spark.ability:notifications:write', 'if-match:notification'])
    ->name('notifications.destroy');

Route::post('health/samples', [HealthController::class, 'samples'])
    ->middleware('spark.ability:data:write')
    ->name('health.samples');

Route::post('live-activities', [LiveActivitiesController::class, 'start'])->middleware('spark.ability:notifications:write')->name('live-activities.start');
Route::patch('live-activities/{id}', [LiveActivitiesController::class, 'update'])->middleware('spark.ability:notifications:write')->name('live-activities.update');
Route::delete('live-activities/{id}', [LiveActivitiesController::class, 'end'])->middleware('spark.ability:notifications:write')->name('live-activities.end');
Route::post('live-activities/{id}/tokens', [LiveActivitiesController::class, 'registerToken'])->middleware('spark.ability:notifications:write')->name('live-activities.tokens');

Route::get('check-ins', [CheckInsController::class, 'index'])
    ->middleware('spark.ability:insights:read')
    ->name('check-ins.index');

Route::get('check-ins/history', [CheckInsController::class, 'history'])
    ->middleware('spark.ability:insights:read')
    ->name('check-ins.history');

Route::get('check-ins/timezone', [CheckInsController::class, 'showTimezone'])
    ->middleware('spark.ability:insights:read')
    ->name('check-ins.timezone.show');

Route::post('check-ins/timezone', [CheckInsController::class, 'storeTimezone'])
    ->middleware('spark.ability:insights:write')
    ->name('check-ins.timezone.store');

Route::post('check-ins', [CheckInsController::class, 'store'])
    ->middleware('spark.ability:insights:write')
    ->name('check-ins.store');

Route::post('check-ins/media', [CheckInsController::class, 'media'])
    ->middleware('spark.ability:insights:write')
    ->name('check-ins.media');

// The read payload is owned by NotificationPreferences; this remains the
// established write handler for the iOS client.
Route::patch('settings/notifications', [NotificationSettingsController::class, 'update'])
    ->middleware(['spark.ability:notifications:write', 'if-match:user'])
    ->name('settings.notifications.update');

Route::post('anomalies/{id}/acknowledge', [AnomaliesController::class, 'acknowledge'])
    ->middleware('spark.ability:insights:write')
    ->name('anomalies.acknowledge');

Route::post('knowledge/events/{id}/reprocess', [KnowledgeReprocessingController::class, 'store'])
    ->middleware(['spark.ability:data:write', 'if-match:event'])
    ->name('knowledge.events.reprocess');

Route::post('bookmarks', [BookmarksController::class, 'store'])
    ->middleware('spark.ability:data:write')
    ->name('bookmarks.store');

Route::post('bookmarks/capture', [CapturedBookmarksController::class, 'store'])
    ->middleware('spark.ability:data:write')
    ->name('bookmarks.capture');

Route::post('captures', [CapturesController::class, 'store'])
    ->middleware('spark.ability:data:write')
    ->name('captures.store');

/*
|--------------------------------------------------------------------------
| API token management
|--------------------------------------------------------------------------
*/

Route::get('api-tokens', [ApiTokensController::class, 'index'])
    ->middleware('spark.ability:mobile:session')
    ->name('api-tokens.index');

/*
 * Token creation requires `tokens:manage`, which OAuthController::scopeToAbilities
 * never issues to an iOS session. A compromised app session therefore cannot mint
 * a longer-lived credential for itself.
 */
Route::post('api-tokens', [ApiTokensController::class, 'store'])
    ->middleware('ability:tokens:manage')
    ->name('api-tokens.store');

Route::delete('api-tokens/{id}', [ApiTokensController::class, 'destroy'])
    ->middleware('spark.ability:tokens:revoke')
    ->name('api-tokens.destroy');

/*
|--------------------------------------------------------------------------
| Up to Speed endpoints
|--------------------------------------------------------------------------
*/

Route::get('up-to-speed', UpToSpeedController::class)
    ->middleware('spark.ability:insights:read')
    ->name('up-to-speed.index');

Route::post('up-to-speed/read', UpToSpeedReadController::class)
    ->middleware('spark.ability:insights:write')
    ->name('up-to-speed.read');

Route::post('up-to-speed/unmark', UpToSpeedUnmarkController::class)
    ->middleware('spark.ability:insights:write')
    ->name('up-to-speed.unmark');

/*
|--------------------------------------------------------------------------
| Flint digest endpoints
|--------------------------------------------------------------------------
*/

Route::get('flint/digests', [FlintDigestsController::class, 'index'])
    ->middleware('spark.ability:flint:read')
    ->name('flint.digests.index');

// Must be registered before the {id} wildcard route below, or "latest" would
// be captured as an id and routed to show() instead.
Route::get('flint/digests/latest', [FlintDigestsController::class, 'latest'])
    ->middleware('spark.ability:flint:read')
    ->name('flint.digests.latest');

Route::get('flint/digests/{id}', [FlintDigestsController::class, 'show'])
    ->middleware('spark.ability:flint:read')
    ->name('flint.digests.show');

Route::post('flint/digests', [FlintDigestsController::class, 'store'])
    ->middleware('spark.ability:flint:write')
    ->name('flint.digests.store');

Route::post('flint/questions/{block}/answer', [FlintDigestsController::class, 'answer'])
    ->middleware('spark.ability:flint:write')
    ->name('flint.questions.answer');

Route::get('flint/questions', [FlintQuestionsController::class, 'index'])
    ->middleware('spark.ability:flint:read')
    ->name('flint.questions.index');

Route::post('flint/questions/{block}/actions', [FlintQuestionsController::class, 'storeAction'])
    ->middleware('spark.ability:flint:write')
    ->name('flint.questions.actions.store');

Route::get('flint/topics', [FlintTopicsController::class, 'index'])
    ->middleware('spark.ability:flint:read')
    ->name('flint.topics.index');

Route::get('flint/topics/{id}', [FlintTopicsController::class, 'show'])
    ->middleware('spark.ability:flint:read')
    ->name('flint.topics.show');

Route::patch('flint/topics/{id}', [FlintTopicsController::class, 'update'])
    ->middleware(['spark.ability:flint:write', 'if-match:object'])
    ->name('flint.topics.update');
Route::post('flint/topics/{id}/tasks', [FlintTopicsController::class, 'storeTask'])
    ->middleware('spark.ability:flint:write')
    ->name('flint.topics.tasks.store');
Route::patch('flint/topics/{id}/tasks/{taskId}', [FlintTopicsController::class, 'updateTask'])
    ->middleware('spark.ability:flint:write')
    ->name('flint.topics.tasks.update');

Route::get('flint/notes', [FlintNotesController::class, 'index'])->middleware('spark.ability:flint:read')->name('flint.notes.index');
Route::post('flint/notes', [FlintNotesController::class, 'store'])->middleware('spark.ability:flint:write')->name('flint.notes.store');
Route::delete('flint/notes/{id}', [FlintNotesController::class, 'destroy'])->middleware('spark.ability:flint:write')->name('flint.notes.destroy');

Route::get('flint/routines/health', FlintRoutineHealthController::class)
    ->middleware('spark.ability:flint:read')
    ->name('flint.routines.health');

Route::get('flint/review', [FlintReviewController::class, 'index'])->middleware('spark.ability:flint:read')->name('flint.review.index');
Route::post('flint/review/{kind}/{id}', [FlintReviewController::class, 'act'])
    ->whereIn('kind', ['receipt_suggestion', 'receipt_auto_match', 'link_suggestion', 'auto_link'])
    ->middleware('spark.ability:flint:write')
    ->name('flint.review.act');

/*
|--------------------------------------------------------------------------
| Money endpoints
|--------------------------------------------------------------------------
*/

Route::get('money/accounts', [MoneyAccountsController::class, 'index'])
    ->middleware('spark.ability:finance:read')
    ->name('money.accounts.index');

Route::get('money/accounts/{id}', [MoneyAccountsController::class, 'show'])
    ->middleware('spark.ability:finance:read')
    ->name('money.accounts.show');

Route::get('money/accounts/{id}/balances', [MoneyAccountsController::class, 'balances'])
    ->middleware('spark.ability:finance:read')
    ->name('money.accounts.balances');

Route::post('money/accounts', [MoneyAccountsController::class, 'store'])
    ->middleware('spark.ability:finance:write')
    ->name('money.accounts.store');

Route::patch('money/accounts/{id}', [MoneyAccountsController::class, 'update'])
    ->middleware(['spark.ability:finance:write', 'if-match:object'])
    ->name('money.accounts.update');

Route::delete('money/accounts/{id}', [MoneyAccountsController::class, 'destroy'])
    ->middleware(['spark.ability:finance:write', 'if-match:object'])
    ->name('money.accounts.destroy');

Route::post('money/accounts/{id}/balances', [MoneyAccountsController::class, 'addBalance'])
    ->middleware(['spark.ability:finance:write', 'if-match:object'])
    ->name('money.accounts.balances.store');

Route::get('money/net-worth', [NetWorthController::class, 'index'])
    ->middleware('spark.ability:finance:read')
    ->name('money.net-worth');
