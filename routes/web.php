<?php

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
