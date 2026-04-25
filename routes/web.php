<?php

use App\Http\Controllers\AasaController;
use App\Http\Controllers\Admin\BlockViewController;
use App\Http\Controllers\Admin\GoCardlessAdminController;
use App\Http\Controllers\Admin\MigrationsController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\WebhookController;
use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Integrations\PluginRegistry;
use App\Jobs\DeleteBinItemsBatch;
use App\Livewire\Day;
use App\Livewire\FinancialAccounts;
use App\Livewire\FinancialAccountShow;
use App\Livewire\IntegrationDetails;
use App\Livewire\Media\Index;
use App\Livewire\Media\Show;
use App\Livewire\MetricDetail;
use App\Livewire\MetricsOverview;
use App\Livewire\ReceiptDetail;
use App\Livewire\Receipts;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return redirect()->route('today.main');
})->name('home');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

// Apple App Site Association for Universal Links
Route::get('.well-known/apple-app-site-association', [AasaController::class, 'show'])
    ->name('aasa');

// Push notification routes (public)
Route::get('push/vapid-public-key', [PushSubscriptionController::class, 'vapidPublicKey'])
    ->name('push.vapid-public-key');

// Push notification routes (authenticated)
Route::middleware(['auth'])->prefix('push')->group(function () {
    Route::post('subscribe', [PushSubscriptionController::class, 'subscribe'])->name('push.subscribe');
    Route::post('unsubscribe', [PushSubscriptionController::class, 'unsubscribe'])->name('push.unsubscribe');
    Route::get('status', [PushSubscriptionController::class, 'status'])->name('push.status');
    Route::get('subscriptions', [PushSubscriptionController::class, 'list'])->name('push.subscriptions');
    Route::delete('subscriptions/{id}', [PushSubscriptionController::class, 'destroy'])->name('push.subscriptions.destroy');
    Route::post('test', [PushSubscriptionController::class, 'test'])->name('push.test');
});

// OAuth PKCE authorization (iOS companion app)
Route::middleware(['auth'])->group(function () {
    Route::get('oauth/authorize', [OAuthController::class, 'authorize'])->name('oauth.authorize');
    Route::post('oauth/authorize', [OAuthController::class, 'approve'])->name('oauth.approve');
    Route::post('oauth/deny', [OAuthController::class, 'deny'])->name('oauth.deny');
});

Route::middleware(['auth'])->group(function () {
    // Day-based timeline routes
    Route::get('day/today', Day::class)->name('today.day');
    Route::get('today', Day::class)->name('today.main');
    Route::get('day/tomorrow', Day::class)->name('tomorrow');
    Route::get('day/yesterday', Day::class)->name('day.yesterday');
    Route::get('day/{date}', Day::class)->name('day.show');
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/sessions', 'settings.sessions')->name('settings.sessions');
    Volt::route('settings/notifications', 'settings.notifications')->name('settings.notifications');
    Volt::route('flint', 'flint.index')->name('flint.index');
    // Removed settings/appearance route
    Volt::route('settings/api-tokens', 'settings.api-tokens')->name('settings.api-tokens');
    Volt::route('settings/integrations', 'integrations.index')->name('integrations.index');
    Volt::route('/updates', 'updates.index')->name('updates.index');
    Volt::route('/events/{event}', 'events.show')->name('events.show');
    Volt::route('/objects/{object}', 'objects.show')->name('objects.show');
    Volt::route('/blocks/{block}', 'blocks.show')->name('blocks.show');
    Volt::route('/tags', 'tags.index')->name('tags.index');
    Volt::route('/tags/{type}/{slug}/{id}', 'tags.show')->name('tags.show');
    Volt::route('notifications', 'notifications.index')->name('notifications.index');

    // Media routes
    Route::get('media', Index::class)->name('media.index');
    Route::get('media/{media:uuid}', Show::class)->name('media.show');

    // Metrics routes
    Route::get('metrics', MetricsOverview::class)->name('metrics.index');
    Route::get('metrics/{metric}', MetricDetail::class)->whereUuid('metric')->name('metrics.show');

    Route::get('integrations/{service}/oauth', [IntegrationController::class, 'oauth'])->name('integrations.oauth');
    Route::get('integrations/{service}/callback', [IntegrationController::class, 'oauthCallback'])->name('integrations.oauth.callback');
    Route::post('integrations/{service}/initialize', [IntegrationController::class, 'initialize'])->name('integrations.initialize');
    Route::get('integrations/{service}/reconnect/{group}', [IntegrationController::class, 'reconnect'])
        ->whereUuid('group')
        ->name('integrations.reconnect');
    Route::get('integrations/groups/{group}/onboarding', [IntegrationController::class, 'onboarding'])
        ->whereUuid('group')
        ->name('integrations.onboarding');
    Route::post('integrations/groups/{group}/instances', [IntegrationController::class, 'storeInstances'])
        ->whereUuid('group')
        ->name('integrations.storeInstances');
    Volt::route('/integrations/{integration}/configure', 'integrations.configure')->name('integrations.configure');

    // Plugin and integration instance detail routes
    Route::get('plugins/{service}', function (string $service) {
        $pluginClass = PluginRegistry::getPlugin($service);
        if (! $pluginClass) {
            abort(404);
        }

        // Get the integration group for this service if exists
        $group = IntegrationGroup::where('service', $service)
            ->where('user_id', Auth::id())
            ->first();

        return view('plugins.show', [
            'service' => $service,
            'pluginClass' => $pluginClass,
            'group' => $group,
        ]);
    })->name('plugins.show');

    Route::get('integrations/{integration}/details', IntegrationDetails::class)
        ->whereUuid('integration')
        ->name('integrations.details');

    // Money routes
    Route::get('money', FinancialAccounts::class)->name('money');
    Route::get('money/{account}', FinancialAccountShow::class)->name('money.show');

    // Receipts routes
    Route::get('money/receipts', Receipts::class)->name('receipts.index');
    Route::get('money/receipts/{id}', ReceiptDetail::class)->whereUuid('id')->name('receipts.show');

    // Bookmarks routes
    Volt::route('bookmarks', 'bookmarks.index')->name('bookmarks');
    Route::redirect('bookmarks/fetch', '/bookmarks?tab=urls')->name('bookmarks.fetch');

    // Map route
    Route::get('map', App\Livewire\Map\Index::class)->name('map.index');

    // Place detail route
    Route::get('places/{place}', App\Livewire\Places\Show::class)->whereUuid('place')->name('places.show');
    // GoCardless bank selection page
    Route::get('integrations/groups/{group}/gocardless/bank-selection', function (IntegrationGroup $group) {
        if ((string) $group->user_id !== (string) Auth::id()) {
            abort(403);
        }

        // Load institutions if not already in session
        if (empty(session('gocardless_institutions_' . $group->id, []))) {
            try {
                $plugin = new GoCardlessBankPlugin;
                $institutions = $plugin->getInstitutions();

                $logData = [
                    'count' => count($institutions),
                    'country' => config('services.gocardless.country', 'GB'),
                ];

                // Only include institution details in local/debug environments
                if (app()->isLocal() || config('app.debug')) {
                    $logData['first_few'] = array_slice($institutions, 0, 3);
                }

                Log::info('GoCardless institutions loaded', $logData);

                if (! empty($institutions)) {
                    session(['gocardless_institutions_' . $group->id => $institutions]);
                    Log::info('Institutions saved to session', [
                        'session_key' => 'gocardless_institutions_' . $group->id,
                        'count' => count($institutions),
                    ]);
                } else {
                    Log::warning('GoCardless API returned empty institutions');
                    session(['gocardless_institutions_' . $group->id => []]);
                }
            } catch (Throwable $e) {
                $logData = [
                    'error' => $e->getMessage(),
                    'country' => config('services.gocardless.country', 'GB'),
                ];

                // Only include full trace in local/debug environments
                if (app()->isLocal() || config('app.debug')) {
                    $logData['trace'] = $e->getTraceAsString();
                }

                Log::error('GoCardless API call failed', $logData);
                session(['gocardless_institutions_' . $group->id => []]);
            }
        }

        return view('livewire.integrations.bank-selection', ['group' => $group]);
    })->whereUuid('group')->name('integrations.gocardless.bankSelection');

    // GoCardless institution selection helper
    Route::post('integrations/groups/{group}/gocardless/institution', function (IntegrationGroup $group) {
        if ((string) $group->user_id !== (string) Auth::id()) {
            abort(403);
        }

        $institutionId = request('institution_id');

        // Validate required institution_id
        if (empty($institutionId)) {
            Log::warning('No institution ID provided in request', [
                'group_id' => $group->id,
                'user_id' => Auth::id(),
            ]);

            return redirect()->back()->withErrors(['institution_id' => 'Please select a bank to continue.']);
        }

        // Get the whitelisted institutions from session
        $institutions = session('gocardless_institutions_' . $group->id, []);

        // Validate institution_id exists in the whitelisted institutions
        $validInstitution = collect($institutions)->firstWhere('id', $institutionId);
        if (! $validInstitution) {
            $logData = [
                'group_id' => $group->id,
                'user_id' => Auth::id(),
                'institution_id' => $institutionId,
            ];

            // Only include available institutions list in local/debug environments
            if (app()->isLocal() || config('app.debug')) {
                $logData['available_institutions'] = array_column($institutions, 'id');
            }

            Log::warning('Invalid institution ID provided', $logData);

            return redirect()->back()->withErrors(['institution_id' => 'Invalid bank selection. Please try again.']);
        }

        // Store the validated institution ID and OAuth group ID to avoid ambiguity
        session([
            'gocardless_institution_id_' . $group->id => (string) $institutionId,
            'gocardless_oauth_group_id' => $group->id,
        ]);

        // Persist institution id and human-readable name on the group for later provider derivation
        $authMeta = $group->auth_metadata ?? [];
        $authMeta['gocardless_institution_id'] = (string) $institutionId;
        if (! empty($validInstitution['name'])) {
            $authMeta['gocardless_institution_name'] = (string) $validInstitution['name'];
        }
        $group->update([
            'auth_metadata' => $authMeta,
        ]);

        $logData = [
            'group_id' => $group->id,
            'user_id' => Auth::id(),
            'institution_id' => $institutionId,
            'session_keys' => [
                'institution_key' => 'gocardless_institution_id_' . $group->id,
                'oauth_group_key' => 'gocardless_oauth_group_id',
            ],
        ];

        // Only include institution name in local/debug environments
        if (app()->isLocal() || config('app.debug')) {
            $logData['institution_name'] = $validInstitution['name'] ?? 'Unknown';
        }

        Log::info('GoCardless institution ID validated and stored in session', $logData);

        // Redirect to OAuth flow
        return redirect()->route('integrations.oauth', ['service' => 'gocardless'])
            ->with('success', __('Bank selection saved.'));
    })->whereUuid('group')->name('integrations.gocardless.setInstitution');
});

// Webhook routes (no auth required)
Route::post('webhook/{service}/{secret}', [WebhookController::class, 'handle'])->name('webhook.handle');

require __DIR__ . '/auth.php';

// Authelia Socialite authentication routes
Route::get('auth/authelia/redirect', function () {
    return Socialite::driver('authelia')->redirect();
})->name('authelia.redirect');

Route::get('auth/authelia/callback', function () {
    $user = Socialite::driver('authelia')->user();
    $email = $user->getEmail();
    if (! $email) {
        return redirect('/')->withErrors(['authelia' => 'No email address returned from Authelia.']);
    }
    $authUser = User::updateOrCreate(
        ['email' => $email],
        [
            'name' => $user->getName() ?: $user->getNickname() ?: $email,
            'password' => Hash::make(Str::random(32)),
        ]
    );
    Auth::login($authUser);

    return redirect('/today');
});

// Admin routes
Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('gocardless', [GoCardlessAdminController::class, 'index'])->name('gocardless.index');
    Route::delete('gocardless/agreements/{agreementId}', [GoCardlessAdminController::class, 'deleteAgreement'])->name('gocardless.deleteAgreement');
    Route::delete('gocardless/requisitions/{requisitionId}', [GoCardlessAdminController::class, 'deleteRequisition'])->name('gocardless.deleteRequisition');

    Route::get('migrations', [MigrationsController::class, 'index'])->name('migrations.index');
    Route::post('migrations/oura', [MigrationsController::class, 'migrateOuraValues'])->name('migrations.oura');

    Volt::route('daynotes', 'admin.daynotes')->name('daynotes.index');
    Volt::route('events', 'admin.events')->name('events.index');
    Volt::route('objects', 'admin.objects')->name('objects.index');
    Volt::route('blocks', 'admin.blocks')->name('blocks.index');
    Volt::route('relationships', 'admin.relationships')->name('relationships.index');
    Volt::route('pending-links', 'admin.pending-links')->name('pending-links.index');
    Volt::route('bin', 'admin.bin')->name('bin.index');
    Volt::route('sense-check', 'admin.sense-check')->name('sense-check.index');
    Volt::route('search', 'admin.search')->name('search.index');
    Volt::route('duplicates', 'admin.duplicates.index')->name('duplicates.index');
    Volt::route('logs', 'pages.admin.logs')->name('logs.index');
    Route::get('block-view', [BlockViewController::class, 'index'])->name('block-view.index');
    Route::post('bin/delete', function () {
        DeleteBinItemsBatch::dispatch(Auth::id());

        return response()->json([
            'message' => 'Deletion process started. All items will be permanently deleted.',
        ]);
    })->name('bin.delete');
    Volt::route('activity', 'admin.activity')->name('activity.index');
    Volt::route('push-notifications', 'admin.push-notifications')->name('push-notifications.index');
});
