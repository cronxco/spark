<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ApiTokensComponentTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_tokens_component_renders(): void
    {
        $user = User::factory()->create();

        $component = Volt::actingAs($user)
            ->test('settings.api-tokens');

        $component->assertStatus(200);
    }

    public function test_api_tokens_component_loads_user_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('Test Token 1');
        $user->createToken('Test Token 2');

        $component = Volt::actingAs($user)
            ->test('settings.api-tokens');

        $component->assertSet('tokens', function ($tokens) {
            return count($tokens) === 2;
        });
    }

    public function test_api_tokens_component_can_create_token(): void
    {
        $user = User::factory()->create();

        $component = Volt::actingAs($user)
            ->test('settings.api-tokens')
            ->set('tokenName', 'My New Token')
            ->call('createToken');

        $component->assertSet('showNewToken', true);
        $component->assertSet('tokenName', '');
        $this->assertNotEmpty($component->get('newToken'));

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'My New Token',
        ]);
    }

    public function test_api_tokens_component_validates_token_name_required(): void
    {
        $user = User::factory()->create();

        $component = Volt::actingAs($user)
            ->test('settings.api-tokens')
            ->set('tokenName', '')
            ->call('createToken');

        $component->assertHasErrors(['tokenName' => 'required']);
    }

    public function test_api_tokens_component_validates_token_name_max_length(): void
    {
        $user = User::factory()->create();

        $component = Volt::actingAs($user)
            ->test('settings.api-tokens')
            ->set('tokenName', str_repeat('a', 256))
            ->call('createToken');

        $component->assertHasErrors(['tokenName' => 'max']);
    }

    public function test_api_tokens_component_can_revoke_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Token to Revoke');
        $tokenId = $token->accessToken->id;

        $component = Volt::actingAs($user)
            ->test('settings.api-tokens')
            ->call('revokeToken', $tokenId);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $tokenId,
        ]);
    }

    public function test_api_tokens_component_updates_list_after_revoke(): void
    {
        $user = User::factory()->create();
        $token1 = $user->createToken('Token 1');
        $token2 = $user->createToken('Token 2');
        $tokenId = $token1->accessToken->id;

        $component = Volt::actingAs($user)
            ->test('settings.api-tokens')
            ->call('revokeToken', $tokenId);

        $component->assertSet('tokens', function ($tokens) {
            return count($tokens) === 1;
        });
    }

    public function test_api_tokens_component_can_hide_new_token(): void
    {
        $user = User::factory()->create();

        $component = Volt::actingAs($user)
            ->test('settings.api-tokens')
            ->set('tokenName', 'Test Token')
            ->call('createToken')
            ->call('hideNewToken');

        $component->assertSet('showNewToken', false);
        $component->assertSet('newToken', '');
    }

    public function test_api_tokens_component_shows_empty_state(): void
    {
        $user = User::factory()->create();

        $component = Volt::actingAs($user)
            ->test('settings.api-tokens');

        $component->assertSet('tokens', []);
    }

    public function test_api_tokens_component_shows_token_info(): void
    {
        $user = User::factory()->create();
        $user->createToken('My API Token');

        $component = Volt::actingAs($user)
            ->test('settings.api-tokens');

        $tokens = $component->get('tokens');

        $this->assertCount(1, $tokens);
        $this->assertEquals('My API Token', $tokens[0]['name']);
        $this->assertArrayHasKey('created_at', $tokens[0]);
        $this->assertArrayHasKey('last_used_at', $tokens[0]);
    }

    public function test_api_tokens_cannot_revoke_nonexistent_token(): void
    {
        $user = User::factory()->create();

        $component = Volt::actingAs($user)
            ->test('settings.api-tokens')
            ->call('revokeToken', 99999);

        // Should not throw exception, just silently ignore
        $component->assertStatus(200);
    }

    public function test_api_tokens_component_clears_token_name_after_creation(): void
    {
        $user = User::factory()->create();

        $component = Volt::actingAs($user)
            ->test('settings.api-tokens')
            ->set('tokenName', 'New Token')
            ->call('createToken');

        $component->assertSet('tokenName', '');
    }

    public function test_multiple_tokens_can_be_created(): void
    {
        $user = User::factory()->create();

        $component = Volt::actingAs($user)
            ->test('settings.api-tokens');

        $component
            ->set('tokenName', 'Token 1')
            ->call('createToken')
            ->call('hideNewToken')
            ->set('tokenName', 'Token 2')
            ->call('createToken')
            ->call('hideNewToken')
            ->set('tokenName', 'Token 3')
            ->call('createToken');

        $this->assertCount(3, $user->tokens);
    }
}
