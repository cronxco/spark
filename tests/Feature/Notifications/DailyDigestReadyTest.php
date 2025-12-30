<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DailyDigestReadyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guests_are_redirected_from_home(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('today.main'));
    }

    #[Test]
    public function authenticated_users_can_access_home(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/');

        $response->assertRedirect(route('today.main'));
    }
}
