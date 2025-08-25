<?php

use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\WebhookController;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
    
    // Manual Financial Integration routes
    Volt::route('/integrations/{integration}/manual-financial', 'manual-financial.dashboard')->name('manual-financial.dashboard');
    Volt::route('/integrations/{integration}/manual-financial/accounts', 'manual-financial.accounts')->name('manual-financial.accounts');
    Volt::route('/integrations/{integration}/manual-financial/balances', 'manual-financial.balances')->name('manual-financial.balances');

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
