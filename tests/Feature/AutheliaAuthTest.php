<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AutheliaAuthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function user_can_authenticate_via_authelia_and_is_created_if_new()
    {
        $socialiteUser = new SocialiteUser;
        $socialiteUser->id = 'authelia-123';
        $socialiteUser->email = 'authelia@example.com';
        $socialiteUser->name = 'Authelia User';
        $socialiteUser->nickname = null;

        Socialite::shouldReceive('driver->user')
            ->once()
            ->andReturn($socialiteUser);

        $response = $this->get('/auth/authelia/callback');
        $response->assertRedirect('/today');
        $this->assertDatabaseHas('users', [
            'email' => 'authelia@example.com',
            'name' => 'Authelia User',
        ]);
        $this->assertTrue(Auth::check());
        $this->assertEquals('authelia@example.com', Auth::user()->email);
    }

    #[Test]
    public function user_authentication_fails_without_email()
    {
        $socialiteUser = new SocialiteUser;
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
