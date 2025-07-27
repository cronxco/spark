<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class AutheliaAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_authenticate_via_authelia_and_is_created_if_new()
    {
        $socialiteUser = new SocialiteUser();
        $socialiteUser->id = 'authelia-123';
        $socialiteUser->email = 'authelia@example.com';
        $socialiteUser->name = 'Authelia User';
        $socialiteUser->nickname = null;

        Socialite::shouldReceive('driver->user')
            ->once()
            ->andReturn($socialiteUser);

        $response = $this->get('/auth/authelia/callback');
        $response->assertRedirect('/dashboard');
        $this->assertDatabaseHas('users', [
            'email' => 'authelia@example.com',
            'name' => 'Authelia User',
        ]);
        $this->assertTrue(Auth::check());
        $this->assertEquals('authelia@example.com', Auth::user()->email);
    }

    public function test_user_authentication_fails_without_email()
    {
        $socialiteUser = new SocialiteUser();
        $socialiteUser->id = 'authelia-456';
        $socialiteUser->email = null;
        $socialiteUser->name = 'No Email';
        $socialiteUser->nickname = null;

        Socialite::shouldReceive('driver->user')
            ->once()
            ->andReturn($socialiteUser);

        $response = $this->get('/auth/authelia/callback');
        $response->assertRedirect('/');
        $response->assertSessionHasErrors(['authelia']);
        $this->assertGuest();
    }
} 