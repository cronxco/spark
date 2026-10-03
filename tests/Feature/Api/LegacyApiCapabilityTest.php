<?php

namespace Tests\Feature\Api;

use App\Models\Block;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\TaskExecution;
use App\Models\User;
use App\Support\SparkAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LegacyApiCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => 'monzo']);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
        ]);
    }

    #[Test]
    public function every_legacy_route_refuses_a_token_without_its_capability(): void
    {
        $event = Event::factory()->create(['integration_id' => $this->integration->id]);
        $block = Block::factory()->create(['event_id' => $event->id]);
        $taskExecution = TaskExecution::factory()->create();
        $otherToken = $this->user->createToken('other', ['data:read'])->accessToken;

        $routes = [
            ['GET', '/api/events', 'data:read'],
            ['GET', "/api/events/{$event->id}", 'data:read'],
            ['POST', '/api/events', 'data:write'],
            ['PATCH', "/api/events/{$event->id}", 'data:write'],
            ['DELETE', "/api/events/{$event->id}", 'data:write'],
            ['POST', '/api/search/events', 'data:read'],
            ['POST', '/api/search/blocks', 'data:read'],
            ['POST', '/api/search/objects', 'data:read'],
            ['POST', '/api/search', 'data:read'],
            ['POST', '/api/search/semantic', 'data:read'],
            ['GET', '/api/tokens', 'tokens:manage'],
            ['DELETE', "/api/tokens/{$otherToken->id}", 'tokens:manage'],
            ['GET', '/api/integrations', 'integrations:read'],
            ['GET', "/api/integrations/{$this->integration->id}", 'integrations:read'],
            ['POST', "/api/integrations/{$this->integration->id}/configure", 'integrations:manage'],
            ['POST', "/api/integrations/{$this->integration->id}/trigger", 'integrations:sync'],
            ['DELETE', "/api/integrations/{$this->integration->id}", 'integrations:manage'],
            ['GET', '/api/assistant/context', 'flint:read'],
            ['POST', "/api/flint/questions/{$block->id}/answer", 'flint:write'],
            ['GET', '/api/task-executions', 'data:read'],
            ['GET', "/api/task-executions/{$taskExecution->id}", 'data:read'],
        ];

        foreach ($routes as [$method, $uri, $ability]) {
            // A token holding every other delegable capability still lacks this one.
            $abilities = array_values(array_diff(SparkAbility::DELEGABLE, [$ability]));
            $token = $this->user->createToken('scoped', $abilities)->plainTextToken;

            $this->withToken($token)
                ->json($method, $uri)
                ->assertStatus(403)
                ->assertJsonPath('required_ability', $ability);

            $this->app['auth']->forgetGuards();
        }

        $this->assertModelExists($event);
        $this->assertModelExists($this->integration);
        $this->assertModelExists($otherToken);
    }

    #[Test]
    public function a_token_with_the_capability_reaches_the_route(): void
    {
        $cases = [
            ['GET', '/api/events', 'data:read'],
            ['GET', '/api/tokens', 'tokens:manage'],
            ['GET', '/api/integrations', 'integrations:read'],
            ['GET', '/api/task-executions', 'data:read'],
        ];

        foreach ($cases as [$method, $uri, $ability]) {
            $token = $this->user->createToken('scoped', [$ability])->plainTextToken;

            $this->withToken($token)->json($method, $uri)->assertOk();

            $this->app['auth']->forgetGuards();
        }
    }

    #[Test]
    public function integration_delete_needs_manage_and_works_with_it(): void
    {
        $token = $this->user->createToken('manager', ['integrations:manage'])->plainTextToken;

        $this->withToken($token)
            ->deleteJson("/api/integrations/{$this->integration->id}")
            ->assertSuccessful();

        $this->assertSoftDeleted($this->integration);
    }

    #[Test]
    public function legacy_read_alias_and_wildcard_tokens_keep_working(): void
    {
        $mcpRead = $this->user->createToken('legacy', ['mcp:read'])->plainTextToken;
        $this->withToken($mcpRead)->getJson('/api/events')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($mcpRead)->postJson("/api/integrations/{$this->integration->id}/configure")->assertStatus(403);
        $this->app['auth']->forgetGuards();

        $wildcard = $this->user->createToken('wildcard', ['*'])->plainTextToken;
        $this->withToken($wildcard)->getJson('/api/integrations')->assertOk();
    }
}
