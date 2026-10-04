<?php

namespace Tests\Feature\Integrations;

use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * INT-06: the service page showed only the first credential group, hiding a
 * second account's instances, and the narrow-screen Add Instance always went
 * to OAuth, even for API-key and webhook services.
 */
class PluginPageAccountsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_account_for_the_service_is_listed(): void
    {
        $user = User::factory()->create();
        $this->instanceIn(IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'github']), 'Work GitHub');
        $this->instanceIn(IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'github']), 'Personal GitHub');
        $this->instanceIn(IntegrationGroup::factory()->create(['user_id' => User::factory()->create()->id, 'service' => 'github']), 'Someone else');

        $this->actingAs($user)->get('/plugins/github')
            ->assertOk()
            ->assertSee('Work GitHub')
            ->assertSee('Personal GitHub')
            ->assertSee('2 active instances')
            ->assertSee('Account 2')
            ->assertDontSee('Someone else');
    }

    #[Test]
    public function a_single_account_keeps_the_original_heading(): void
    {
        $user = User::factory()->create();
        $this->instanceIn(IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'github']), 'Work GitHub');

        $this->actingAs($user)->get('/plugins/github')
            ->assertOk()
            ->assertSee('Your Instances (1)')
            ->assertDontSee('Account 1');
    }

    #[Test]
    public function both_layouts_add_a_non_oauth_instance_through_initialize(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/plugins/hevy')->assertOk();

        $initialize = route('integrations.initialize', ['service' => 'hevy']);
        $this->assertSame(2, substr_count($response->getContent(), 'action="' . $initialize . '"'));
        $this->assertStringNotContainsString(route('integrations.oauth', 'hevy'), $response->getContent());
    }

    #[Test]
    public function both_layouts_add_an_oauth_instance_through_the_provider(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/plugins/github')->assertOk();

        $this->assertSame(2, substr_count($response->getContent(), route('integrations.oauth', 'github')));
    }

    private function instanceIn(IntegrationGroup $group, string $name): Integration
    {
        return Integration::factory()->create([
            'user_id' => $group->user_id,
            'integration_group_id' => $group->id,
            'service' => $group->service,
            'name' => $name,
        ]);
    }
}
