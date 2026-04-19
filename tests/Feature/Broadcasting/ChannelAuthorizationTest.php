<?php

namespace Tests\Feature\Broadcasting;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The default `log` broadcaster has no channel-auth signing logic — it returns 200
        // for everything. Switch to reverb (Pusher protocol) so the channel callback in
        // routes/channels.php is actually consulted.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'driver' => 'reverb',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'app_id' => 'test-app',
                'options' => [
                    'host' => 'localhost',
                    'port' => 8080,
                    'scheme' => 'http',
                    'useTLS' => false,
                ],
            ],
        ]);

        // Channels are registered against whichever broadcaster is active when
        // routes/channels.php loads at boot (the default `log` driver in tests).
        // Re-register them against the freshly-switched reverb driver so the
        // channel callback in routes/channels.php is actually consulted.
        require base_path('routes/channels.php');
    }

    #[Test]
    public function unauthenticated_requests_are_rejected(): void
    {
        $response = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-App.Models.User.some-id',
            'socket_id' => '1234.5678',
        ]);

        $response->assertUnauthorized();
    }

    #[Test]
    public function sanctum_token_authorizes_users_own_private_channel(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['ios:*']);

        $response = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-App.Models.User.' . $user->getKey(),
            'socket_id' => '1234.5678',
        ]);

        $response->assertOk();
        // Pusher protocol auth response shape: {"auth":"<key>:<signature>"}
        $response->assertJsonStructure(['auth']);
    }

    #[Test]
    public function sanctum_token_cannot_authorize_other_users_private_channel(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        Sanctum::actingAs($alice, ['ios:*']);

        $response = $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-App.Models.User.' . $bob->getKey(),
            'socket_id' => '1234.5678',
        ]);

        $response->assertForbidden();
    }
}
