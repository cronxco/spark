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

    /**
     * The abilities a credential needs to reach the creation endpoint at all.
     * An iOS OAuth session never holds `tokens:manage`.
     *
     * @var array<int, string>
     */
    private const MANAGER = ['ios:read', 'tokens:manage', 'data:read', 'insights:read'];

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
        $this->user->createToken('CLI', ['data:read']);
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
        $this->user->createToken('MCP', ['data:read']);
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/api-tokens')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'MCP');
    }

    #[Test]
    public function store_creates_token_and_returns_plaintext(): void
    {
        Sanctum::actingAs($this->user, self::MANAGER);

        $response = $this->postJson('/api/v1/mobile/api-tokens', [
            'name' => 'My data token',
            'abilities' => ['data:read'],
        ])
            ->assertStatus(201)
            ->assertJsonStructure(['id', 'name', 'plaintext'])
            ->assertJsonPath('name', 'My data token');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
            'name' => 'My data token',
        ]);

        $token = $this->user->tokens()->where('name', 'My data token')->first();
        $this->assertSame(['data:read'], $token->abilities);

        // Plaintext is the bearer that follows the `id|secret` Sanctum format.
        $this->assertStringContainsString('|', $response->json('plaintext'));
    }

    /*
     |--------------------------------------------------------------------------
     | Wildcard containment (PSEC-01 / APO-01)
     |--------------------------------------------------------------------------
     |
     | A `['*']` token satisfies every tokenCan() check in the application, so
     | no request may produce one. Each of the paths below previously did.
     */

    #[Test]
    public function store_rejects_a_request_with_no_abilities(): void
    {
        Sanctum::actingAs($this->user, self::MANAGER);

        $this->postJson('/api/v1/mobile/api-tokens', ['name' => 'Default'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['abilities']);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
            'name' => 'Default',
        ]);
    }

    #[Test]
    public function store_rejects_an_empty_abilities_array(): void
    {
        Sanctum::actingAs($this->user, self::MANAGER);

        $this->postJson('/api/v1/mobile/api-tokens', ['name' => 'Empty', 'abilities' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['abilities']);
    }

    #[Test]
    public function store_rejects_an_explicit_wildcard(): void
    {
        Sanctum::actingAs($this->user, self::MANAGER);

        $this->postJson('/api/v1/mobile/api-tokens', ['name' => 'Wild', 'abilities' => ['*']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['abilities.0']);
    }

    #[Test]
    public function store_rejects_an_ios_scope_wildcard(): void
    {
        Sanctum::actingAs($this->user, self::MANAGER);

        $this->postJson('/api/v1/mobile/api-tokens', ['name' => 'Wild', 'abilities' => ['ios:*']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['abilities.0']);
    }

    #[Test]
    public function store_rejects_an_unknown_ability(): void
    {
        Sanctum::actingAs($this->user, self::MANAGER);

        $this->postJson('/api/v1/mobile/api-tokens', [
            'name' => 'Invented',
            'abilities' => ['data:read', 'not:a:real:ability'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['abilities.1']);
    }

    #[Test]
    public function store_rejects_ios_session_abilities_rather_than_falling_back_to_wildcard(): void
    {
        Sanctum::actingAs($this->user, self::MANAGER);

        // Previously these were silently stripped and the empty remainder became
        // ['*'] — so asking for the narrowest scopes produced the widest token.
        $this->postJson('/api/v1/mobile/api-tokens', [
            'name' => 'Sneaky',
            'abilities' => ['ios:read', 'ios:write'],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
            'name' => 'Sneaky',
        ]);
    }

    #[Test]
    public function an_ios_session_cannot_reach_the_creation_endpoint(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/api-tokens', [
            'name' => 'From the app',
            'abilities' => ['data:read'],
        ])->assertStatus(403);

        $this->assertSame(0, $this->user->tokens()->where('name', 'From the app')->count());
    }

    #[Test]
    public function store_cannot_delegate_more_than_the_issuing_token_holds(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'tokens:manage', 'data:read']);

        $this->postJson('/api/v1/mobile/api-tokens', [
            'name' => 'Widening',
            'abilities' => ['data:read', 'finance:write'],
        ])->assertStatus(403);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
            'name' => 'Widening',
        ]);
    }

    #[Test]
    public function store_allows_a_strict_subset_of_the_issuing_token(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'tokens:manage', 'data:read', 'finance:read']);

        $this->postJson('/api/v1/mobile/api-tokens', [
            'name' => 'Narrower',
            'abilities' => ['data:read'],
        ])->assertStatus(201);

        $token = $this->user->tokens()->where('name', 'Narrower')->first();
        $this->assertSame(['data:read'], $token->abilities);
    }

    #[Test]
    public function store_requires_name(): void
    {
        Sanctum::actingAs($this->user, self::MANAGER);

        $this->postJson('/api/v1/mobile/api-tokens', ['abilities' => ['data:read']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    #[Test]
    public function destroy_revokes_a_token(): void
    {
        $token = $this->user->createToken('MCP', ['data:read'])->accessToken;
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
        $token = $other->createToken('Theirs', ['data:read'])->accessToken;
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->deleteJson("/api/v1/mobile/api-tokens/{$token->getKey()}")
            ->assertStatus(404);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->getKey()]);
    }

    #[Test]
    public function destroy_requires_write_ability(): void
    {
        $token = $this->user->createToken('MCP', ['data:read'])->accessToken;
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->deleteJson("/api/v1/mobile/api-tokens/{$token->getKey()}")
            ->assertStatus(403);
    }
}
