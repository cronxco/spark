<?php

use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

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
    Route::get('/integrations/{service}/oauth', [IntegrationController::class, 'oauth'])->name('integrations.oauth');
    Route::get('/integrations/{service}/callback', [IntegrationController::class, 'oauthCallback'])->name('integrations.oauth.callback');
    Route::post('/integrations/{service}/initialize', [IntegrationController::class, 'initialize'])->name('integrations.initialize');
    Volt::route('/integrations/{integration}/configure', 'integrations.configure')->name('integrations.configure');

});

// Webhook routes (no auth required)
Route::post('/webhook/{service}/{secret}', [WebhookController::class, 'handle'])->name('webhook.handle');

require __DIR__.'/auth.php';

// Authelia Socialite authentication routes
Route::get('/auth/authelia/redirect', function () {
    return Socialite::driver('authelia')->redirect();
})->name('authelia.redirect');

Route::get('/auth/authelia/callback', function () {
    $user = Socialite::driver('authelia')->user();
    $email = $user->getEmail();
    if (!$email) {
        return redirect('/')->withErrors(['authelia' => 'No email address returned from Authelia.']);
    }
    $authUser = \App\Models\User::updateOrCreate(
        ['email' => $email],
        [
            'name' => $user->getName() ?: $user->getNickname() ?: $email,
            'password' => Hash::make(Str::random(32)),
        ]
    );
    \Illuminate\Support\Facades\Auth::login($authUser);
    return redirect('/dashboard');
});
