<?php

use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\WebhookController;
use App\Integrations\GoCardless\GoCardlessBankPlugin;
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
    return view('welcome');
})->name('home');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
    Volt::route('settings/api-tokens', 'settings.api-tokens')->name('settings.api-tokens');
});

// Integration routes
Route::middleware(['auth'])->group(function () {
    Volt::route('/integrations', 'integrations.index')->name('integrations.index');
    Volt::route('/updates', 'updates.index')->name('updates.index');
    Volt::route('/events', 'events')->name('events.index');
    Route::get('integrations/{service}/oauth', [IntegrationController::class, 'oauth'])->name('integrations.oauth');
    Route::get('integrations/{service}/callback', [IntegrationController::class, 'oauthCallback'])->name('integrations.oauth.callback');
    Route::post('integrations/{service}/initialize', [IntegrationController::class, 'initialize'])->name('integrations.initialize');
    Route::get('integrations/groups/{group}/onboarding', [IntegrationController::class, 'onboarding'])
        ->whereUuid('group')
        ->name('integrations.onboarding');
    Route::post('integrations/groups/{group}/instances', [IntegrationController::class, 'storeInstances'])
        ->whereUuid('group')
        ->name('integrations.storeInstances');
    Volt::route('/integrations/{integration}/configure', 'integrations.configure')->name('integrations.configure');

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
            } catch (\Throwable $e) {
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

    // Debug route for testing Nordigen API (remove in production)
    Route::get('debug/gocardless-test', function () {
        if (! app()->environment('local')) {
            abort(404);
        }

        $secretId = config('services.gocardless.secret_id');
        $secretKey = config('services.gocardless.secret_key');
        $country = config('services.gocardless.country', 'GB');

        $result = [
            'credentials_loaded' => ! empty($secretId) && ! empty($secretKey),
            'secret_id_length' => strlen($secretId),
            'secret_key_length' => strlen($secretKey),
            'country' => $country,
        ];

        try {
            $plugin = new \App\Integrations\GoCardless\GoCardlessBankPlugin;
            $result['plugin_created'] = true;

            $institutions = $plugin->getInstitutions($country);
            $result['institutions_loaded'] = true;
            $result['institution_count'] = count($institutions);
            $result['first_institution'] = $institutions[0] ?? null;

        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
            $result['error_class'] = get_class($e);
            $result['trace'] = $e->getTraceAsString();
        }

        return response()->json($result);
    })->name('debug.gocardless');

    // Debug route for refreshing GoCardless integration names
    Route::get('debug/gocardless-refresh-names/{groupId}', function (string $groupId) {
        if (! app()->environment('local')) {
            abort(404);
        }

        $group = IntegrationGroup::find($groupId);
        if (! $group || $group->service !== 'gocardless') {
            return response()->json(['error' => 'Invalid group ID or not GoCardless'], 400);
        }

        $plugin = new \App\Integrations\GoCardless\GoCardlessBankPlugin;
        $result = $plugin->refreshIntegrationNames($group);

        return response()->json($result);
    })->name('debug.gocardless-refresh-names');

    // Test route for OAuth redirect
    Route::get('debug/oauth-test', function () {
        if (! app()->environment('local')) {
            abort(404);
        }

        // Test the OAuth route directly
        $oauthUrl = route('integrations.oauth', ['service' => 'gocardless']);

        return response()->json([
            'oauth_route_exists' => true,
            'oauth_url' => $oauthUrl,
            'current_user' => Auth::check() ? Auth::id() : null,
        ]);
    })->name('debug.oauth-test');

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

    return redirect('/dashboard');
});

// Admin routes
Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('gocardless', [App\Http\Controllers\Admin\GoCardlessAdminController::class, 'index'])->name('gocardless.index');
    Route::delete('gocardless/agreements/{agreementId}', [App\Http\Controllers\Admin\GoCardlessAdminController::class, 'deleteAgreement'])->name('gocardless.deleteAgreement');
    Route::delete('gocardless/requisitions/{requisitionId}', [App\Http\Controllers\Admin\GoCardlessAdminController::class, 'deleteRequisition'])->name('gocardless.deleteRequisition');
});
