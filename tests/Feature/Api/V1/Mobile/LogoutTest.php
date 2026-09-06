<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\OAuthRefreshToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\NewAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PSEC-04 / TA-01, server half.
 *
 * Sign-out previously deleted only the client's device registration; the
 * Sanctum access token and its paired refresh token stayed valid until natural
 * expiry, so a stolen device kept working after the user had signed out.
 */
class LogoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
    }

    /**
     * Issue a real iOS session token and return [plaintext, model].
     *
     * This endpoint acts on the identity of the calling token, and Sanctum's
     * actingAs() helper installs a mock with no usable key — so these tests
     * authenticate over the wire like the app does.
     *
     * @return array{0: string, 1: \Laravel\Sanctum\PersonalAccessToken}
     */
    private function issueSession(User $user): array
    {
        /** @var NewAccessToken $issued */
        $issued = $user->createToken('iPhone', ['ios:read', 'ios:write']);

        return [$issued->plainTextToken, $issued->accessToken];
    }

    #[Test]
    public function requires_authentication(): void
    {
        $this->postJson('/api/v1/mobile/logout')->assertStatus(401);
    }

    #[Test]
    public function it_deletes_the_calling_access_token(): void
    {
        $user = User::factory()->create();
        [$plain, $token] = $this->issueSession($user);

        $this->withToken($plain)->postJson('/api/v1/mobile/logout')->assertStatus(204);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->getKey()]);
    }

    #[Test]
    public function it_revokes_the_paired_refresh_token(): void
    {
        $user = User::factory()->create();
        [$plain, $token] = $this->issueSession($user);

        $refresh = OAuthRefreshToken::create([
            'user_id' => $user->getKey(),
            'token_hash' => hash('sha256', 'refresh-plaintext'),
            'access_token_id' => $token->getKey(),
            'client_id' => 'co.cronx.sparkapp',
            'device_name' => 'iPhone',
            'scope' => 'ios:*',
            'expires_at' => now()->addDays(30),
        ]);

        $this->withToken($plain)->postJson('/api/v1/mobile/logout')->assertStatus(204);

        $this->assertNotNull($refresh->fresh()->revoked_at);
    }

    #[Test]
    public function it_leaves_other_devices_and_tokens_alone(): void
    {
        $user = User::factory()->create();
        [$plain, $current] = $this->issueSession($user);
        $otherDevice = $user->createToken('iPad', ['ios:read', 'ios:write'])->accessToken;
        $personalToken = $user->createToken('CLI', ['data:read'])->accessToken;

        $otherRefresh = OAuthRefreshToken::create([
            'user_id' => $user->getKey(),
            'token_hash' => hash('sha256', 'other-refresh'),
            'access_token_id' => $otherDevice->getKey(),
            'client_id' => 'co.cronx.sparkapp',
            'device_name' => 'iPad',
            'scope' => 'ios:*',
            'expires_at' => now()->addDays(30),
        ]);

        $this->withToken($plain)->postJson('/api/v1/mobile/logout')->assertStatus(204);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherDevice->getKey()]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $personalToken->getKey()]);
        $this->assertNull($otherRefresh->fresh()->revoked_at);
    }

    #[Test]
    public function it_cannot_revoke_another_users_session(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        [$plain, $token] = $this->issueSession($user);
        $othersToken = $other->createToken('Their iPhone', ['ios:read', 'ios:write'])->accessToken;

        $othersRefresh = OAuthRefreshToken::create([
            'user_id' => $other->getKey(),
            'token_hash' => hash('sha256', 'theirs'),
            'access_token_id' => $othersToken->getKey(),
            'client_id' => 'co.cronx.sparkapp',
            'device_name' => 'Their iPhone',
            'scope' => 'ios:*',
            'expires_at' => now()->addDays(30),
        ]);

        $this->withToken($plain)->postJson('/api/v1/mobile/logout')->assertStatus(204);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $othersToken->getKey()]);
        $this->assertNull($othersRefresh->fresh()->revoked_at);
    }

    #[Test]
    public function it_is_reachable_by_a_read_only_session(): void
    {
        $user = User::factory()->create();
        $issued = $user->createToken('iPhone', ['ios:read']);

        // Signing out must never be blocked by lacking the write scope.
        $this->withToken($issued->plainTextToken)
            ->postJson('/api/v1/mobile/logout')
            ->assertStatus(204);
    }
}
