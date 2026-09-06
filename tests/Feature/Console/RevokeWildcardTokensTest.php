<?php

namespace Tests\Feature\Console;

use App\Models\OAuthRefreshToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PSEC-01 / TA-02, the half a code change cannot reach.
 *
 * SparkAbility::canDelegate() stops new wildcard tokens being minted, but every
 * token issued before it still carries `["*"]` and still satisfies every
 * tokenCan() in the application — including the non-delegable ios and mcp
 * abilities. Those have to be swept explicitly.
 */
class RevokeWildcardTokensTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_revokes_a_wildcard_token(): void
    {
        $token = User::factory()->create()->createToken('Legacy', ['*'])->accessToken;

        $this->artisan('tokens:revoke-wildcard')->assertSuccessful();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->getKey()]);
    }

    #[Test]
    public function it_leaves_a_scoped_token_alone(): void
    {
        $scoped = User::factory()->create()->createToken('CLI', ['data:read'])->accessToken;

        $this->artisan('tokens:revoke-wildcard')->assertSuccessful();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $scoped->getKey()]);
    }

    #[Test]
    public function it_revokes_the_refresh_token_paired_with_a_wildcard_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Legacy', ['*'])->accessToken;

        $refresh = OAuthRefreshToken::create([
            'user_id' => $user->getKey(),
            'token_hash' => hash('sha256', 'paired'),
            'access_token_id' => $token->getKey(),
            'client_id' => 'co.cronx.sparkapp',
            'device_name' => 'iPhone',
            'scope' => 'ios:*',
            'expires_at' => now()->addDays(30),
        ]);

        $this->artisan('tokens:revoke-wildcard')->assertSuccessful();

        // Deleting the access token alone would leave this able to mint a replacement.
        $this->assertNotNull($refresh->fresh()->revoked_at);
    }

    #[Test]
    public function it_leaves_a_refresh_token_paired_with_a_scoped_token_alone(): void
    {
        $user = User::factory()->create();
        $user->createToken('Legacy', ['*']);
        $scoped = $user->createToken('iPad', ['ios:read', 'ios:write'])->accessToken;

        $refresh = OAuthRefreshToken::create([
            'user_id' => $user->getKey(),
            'token_hash' => hash('sha256', 'scoped-pair'),
            'access_token_id' => $scoped->getKey(),
            'client_id' => 'co.cronx.sparkapp',
            'device_name' => 'iPad',
            'scope' => 'ios:*',
            'expires_at' => now()->addDays(30),
        ]);

        $this->artisan('tokens:revoke-wildcard')->assertSuccessful();

        $this->assertNull($refresh->fresh()->revoked_at);
    }

    #[Test]
    public function it_sweeps_every_user(): void
    {
        $alice = User::factory()->create()->createToken('Alice legacy', ['*'])->accessToken;
        $bob = User::factory()->create()->createToken('Bob legacy', ['*'])->accessToken;

        $this->artisan('tokens:revoke-wildcard')->assertSuccessful();

        $this->assertSame(0, PersonalAccessToken::query()->whereKey([$alice->getKey(), $bob->getKey()])->count());
    }

    #[Test]
    public function a_dry_run_reports_the_inventory_without_revoking(): void
    {
        $token = User::factory()->create()->createToken('Legacy', ['*'])->accessToken;

        $this->artisan('tokens:revoke-wildcard --dry-run')
            ->expectsOutputToContain('1 token(s) hold the "*" ability')
            ->assertSuccessful();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->getKey()]);
    }

    #[Test]
    public function it_is_a_no_op_when_there_is_nothing_to_revoke(): void
    {
        User::factory()->create()->createToken('CLI', ['data:read']);

        $this->artisan('tokens:revoke-wildcard')
            ->expectsOutput('No wildcard tokens found.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        User::factory()->create()->createToken('Legacy', ['*']);

        $this->artisan('tokens:revoke-wildcard')->assertSuccessful();
        $this->artisan('tokens:revoke-wildcard')
            ->expectsOutput('No wildcard tokens found.')
            ->assertSuccessful();
    }
}
