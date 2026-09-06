<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function user_can_create_api_token()
    {
        $user = User::factory()->create();
        // Authority attenuates, so the issuing credential must itself hold what
        // it delegates.
        Sanctum::actingAs($user, ['data:read']);

        $response = $this->postJson('/api/tokens/create', [
            'token_name' => 'Test Token',
            'abilities' => ['data:read'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'token',
            'token_name',
            'created_at',
        ]);
        $response->assertJson([
            'token_name' => 'Test Token',
        ]);

        $this->assertSame(
            ['data:read'],
            $user->tokens()->where('name', 'Test Token')->first()->abilities,
        );
    }

    #[Test]
    public function creating_a_token_requires_explicit_abilities(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['data:read']);

        // This endpoint previously omitted the abilities argument entirely and
        // so minted a ['*'] token, which satisfies every capability check in
        // the application.
        $this->postJson('/api/tokens/create', ['token_name' => 'Wildcard'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['abilities']);

        $this->assertSame(0, $user->tokens()->where('name', 'Wildcard')->count());
    }

    #[Test]
    public function creating_a_token_rejects_a_non_delegable_ability(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['data:read']);

        $this->postJson('/api/tokens/create', [
            'token_name' => 'Wildcard',
            'abilities' => ['*'],
        ])->assertStatus(422);
    }

    #[Test]
    public function user_can_list_their_tokens()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Create a token first
        $user->createToken('Test Token');

        $response = $this->getJson('/api/tokens');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'tokens' => [
                '*' => [
                    'id',
                    'name',
                    'created_at',
                    'last_used_at',
                ],
            ],
        ]);
        $response->assertJson([
            'tokens' => [
                [
                    'name' => 'Test Token',
                ],
            ],
        ]);
    }

    #[Test]
    public function user_can_revoke_token()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Create a token
        $token = $user->createToken('Test Token');

        $response = $this->deleteJson("/api/tokens/{$token->accessToken->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Token revoked successfully',
        ]);

        // Verify token is deleted
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);
    }

    #[Test]
    public function user_cannot_revoke_nonexistent_token()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/tokens/999');

        $response->assertStatus(404);
        $response->assertJson([
            'error' => 'Token not found',
        ]);
    }

    #[Test]
    public function unauthenticated_user_cannot_access_token_endpoints()
    {
        $response = $this->postJson('/api/tokens/create');
        $response->assertStatus(401);

        $response = $this->getJson('/api/tokens');
        $response->assertStatus(401);

        $response = $this->deleteJson('/api/tokens/1');
        $response->assertStatus(401);
    }
}
