<?php

namespace Tests\Feature;

use App\Livewire\Actions\Logout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class LogoutActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_action_logs_out_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertTrue(Auth::check());

        $logout = new Logout();
        $logout();

        $this->assertFalse(Auth::check());
    }

    public function test_logout_action_invalidates_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Store a value in session
        Session::put('test_key', 'test_value');
        $this->assertEquals('test_value', Session::get('test_key'));

        $logout = new Logout();
        $logout();

        // Session should be invalidated
        $this->assertNull(Session::get('test_key'));
    }

    public function test_logout_action_returns_redirect_to_home(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $logout = new Logout();
        $response = $logout();

        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);
        $this->assertEquals(url('/'), $response->getTargetUrl());
    }

    public function test_logout_action_regenerates_csrf_token(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $originalToken = Session::token();

        $logout = new Logout();
        $logout();

        // Token should be different after logout
        $this->assertNotEquals($originalToken, Session::token());
    }

    public function test_logout_via_post_request(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post('/logout');

        $response->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_guest_cannot_logout(): void
    {
        $response = $this->post('/logout');

        $response->assertRedirect('/login');
    }
}
