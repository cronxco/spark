<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiTokensControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);

        $this->user = User::factory()->create();
    }

    #[Test]
    public function requires_authentication(): void
    {
        $this->getJson('/api/v1/mobile/api-tokens')->assertStatus(401);
    }

    #[Test]
    public function index_lists_user_tokens(): void
    {
        $this->user->createToken('CLI', ['*']);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/api-tokens')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'CLI')
            ->assertJsonStructure([['id', 'name', 'abilities', 'last_used_at', 'created_at']]);
    }

    #[Test]
    public function index_hides_ios_app_session_tokens(): void
    {
        $this->user->createToken('iOS App', ['ios:read', 'ios:write']);
        $this->user->createToken('MCP', ['*']);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/api-tokens')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'MCP');
    }

    #[Test]
    public function store_creates_token_and_returns_plaintext(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $response = $this->postJson('/api/v1/mobile/api-tokens', [
            'name' => 'My MCP token',
            'abilities' => ['mcp:read'],
        ])
            ->assertStatus(201)
            ->assertJsonStructure(['id', 'name', 'plaintext'])
            ->assertJsonPath('name', 'My MCP token');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
            'name' => 'My MCP token',
        ]);

        // Plaintext is the bearer that follows the `id|secret` Sanctum format.
        $this->assertStringContainsString('|', $response->json('plaintext'));
    }

    #[Test]
    public function store_defaults_to_wildcard_when_no_abilities_given(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/api-tokens', ['name' => 'Default'])
            ->assertStatus(201);

        $token = $this->user->tokens()->where('name', 'Default')->first();
        $this->assertSame(['*'], $token->abilities);
    }

    #[Test]
    public function store_strips_ios_session_abilities(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/api-tokens', [
            'name' => 'Sneaky',
            'abilities' => ['ios:write', 'mcp:read'],
        ])->assertStatus(201);

        $token = $this->user->tokens()->where('name', 'Sneaky')->first();
        $this->assertSame(['mcp:read'], $token->abilities);
    }

    #[Test]
    public function store_requires_write_ability(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->postJson('/api/v1/mobile/api-tokens', ['name' => 'Nope'])
            ->assertStatus(403);
    }

    #[Test]
    public function store_requires_name(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/api-tokens', ['abilities' => ['mcp:read']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    #[Test]
    public function destroy_revokes_a_token(): void
    {
        $token = $this->user->createToken('MCP', ['*'])->accessToken;
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->deleteJson("/api/v1/mobile/api-tokens/{$token->getKey()}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->getKey()]);
    }

    #[Test]
    public function destroy_will_not_revoke_ios_session_tokens(): void
    {
        $token = $this->user->createToken('iOS App', ['ios:read', 'ios:write'])->accessToken;
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->deleteJson("/api/v1/mobile/api-tokens/{$token->getKey()}")
            ->assertStatus(404);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->getKey()]);
    }

    #[Test]
    public function destroy_denies_other_users_tokens(): void
    {
        $other = User::factory()->create();
        $token = $other->createToken('Theirs', ['*'])->accessToken;
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->deleteJson("/api/v1/mobile/api-tokens/{$token->getKey()}")
            ->assertStatus(404);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->getKey()]);
    }

    #[Test]
    public function destroy_requires_write_ability(): void
    {
        $token = $this->user->createToken('MCP', ['*'])->accessToken;
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->deleteJson("/api/v1/mobile/api-tokens/{$token->getKey()}")
            ->assertStatus(403);
    }
}
