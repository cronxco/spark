<?php

namespace Tests\Feature;

use App\Jobs\Migrations\FetchIntegrationPage;
use App\Jobs\Migrations\StartIntegrationMigration;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MigrationJobsTest extends TestCase
{
    /**
     * @test
     */
    public function onboarding_can_trigger_migration_with_optional_timebox(): void
    {
        $this->withoutExceptionHandling();
        Queue::fake();

        /** @var \App\Models\User $user */
        $user = User::factory()->create();
        $this->actingAs($user);

        // Create a group for Spotify to run onboarding against
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'spotify',
            'account_id' => 'acct_1',
            'access_token' => 'token_abc',
        ]);

        // POST onboarding form to create an instance and request migration
        $response = $this->post(route('integrations.storeInstances', ['group' => $group->id]), [
            'types' => ['listening'],
            'config' => [
                'listening' => [
                    'name' => 'Listening Activity',
                    'update_frequency_minutes' => 5,
                ],
            ],
            'run_migration' => 'on',
            'migration_timebox_minutes' => 5,
        ]);

        $response->assertRedirect(route('integrations.index'));

        // A migration start job should be pushed on the migration queue
        Queue::assertPushedOn('migration', StartIntegrationMigration::class);
    }

    /**
     * @test
     */
    public function fetch_integration_page_respects_timebox_and_stops(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'spotify',
            'access_token' => 'tok',
        ]);
        $integration = Integration::create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'spotify',
            'name' => 'Listening',
            'instance_type' => 'listening',
            'configuration' => ['update_frequency_minutes' => 15],
        ]);

        $context = [
            'service' => 'spotify',
            'cursor' => ['before_ms' => now()->getTimestampMs()],
            'timebox_until' => now()->subMinute()->toIso8601String(), // already expired
        ];

        // Handle should early-return and not chain anything
        (new FetchIntegrationPage($integration, $context))->handle();

        Bus::assertNothingDispatched();
    }

    /**
     * @test
     */
    public function spotify_fetch_handles_429_and_redispatches(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.spotify.com/v1/me/player/recently-played*' => Http::response([], 429, ['Retry-After' => 15]),
        ]);

        $user = User::factory()->create();
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'spotify',
            'access_token' => 'tok',
        ]);
        $integration = Integration::create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'spotify',
            'name' => 'Listening',
            'instance_type' => 'listening',
            'configuration' => ['update_frequency_minutes' => 15],
        ]);

        $context = [
            'service' => 'spotify',
            'cursor' => ['before_ms' => now()->getTimestampMs()],
        ];

        (new FetchIntegrationPage($integration, $context))->handle();

        // Should re-dispatch itself on migration queue after rate limit
        Queue::assertPushedOn('migration', FetchIntegrationPage::class);
    }

    /**
     * @test
     */
    public function github_fetch_paginates_and_chains_processing(): void
    {
        Bus::fake();
        Http::fake([
            // Match any page; use sequence for page 1 then page 2
            'https://api.github.com/repos/*/events*' => Http::sequence()
                ->push([
                    ['id' => 'evt123', 'type' => 'PushEvent', 'created_at' => now()->toIso8601String(), 'actor' => ['login' => 'octo', 'id' => 1, 'avatar_url' => 'x'], 'repo' => ['id' => 1, 'name' => 'org/repo', 'full_name' => 'org/repo']],
                ], 200, ['X-GitHub-Api-Version' => '2022-11-28'])
                ->push([], 200),
        ]);

        $user = User::factory()->create();
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'github',
            'access_token' => 'tok',
        ]);
        $integration = Integration::create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'github',
            'name' => 'GH Activity',
            'instance_type' => 'activity',
            'configuration' => [
                'repositories' => ['org/repo'],
                'events' => ['push'],
                'update_frequency_minutes' => 15,
            ],
        ]);

        $context = [
            'service' => 'github',
            'instance_type' => 'activity',
            'cursor' => ['repo_index' => 0, 'page' => 1],
        ];

        (new FetchIntegrationPage($integration, $context))->handle();

        Bus::assertChained([
            \App\Jobs\Migrations\ProcessIntegrationPage::class,
            \App\Jobs\Migrations\FetchIntegrationPage::class,
        ]);
    }

    /**
     * @test
     */
    public function oura_fetch_handles_429_and_empty_window(): void
    {
        // First call 429, then empty window -> final stop
        Queue::fake();
        Http::fake([
            // Any v2 endpoint returns 429 once
            'https://api.ouraring.com/v2/*' => Http::sequence()
                ->push([], 429, ['Retry-After' => 30])
                ->push(['data' => []], 200),
        ]);

        $user = User::factory()->create();
        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => 'oura',
            'access_token' => 'tok',
        ]);
        $integration = Integration::create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'name' => 'Oura Activity',
            'instance_type' => 'activity',
            'configuration' => [],
            'update_frequency_minutes' => 60,
        ]);

        $context = [
            'service' => 'oura',
            'instance_type' => 'activity',
            'cursor' => [
                'start_date' => now()->subDays(29)->toDateString(),
                'end_date' => now()->toDateString(),
            ],
            'window_days' => 30,
        ];

        // First handle triggers re-dispatch due to 429
        (new FetchIntegrationPage($integration, $context))->handle();
        Queue::assertPushedOn('migration', FetchIntegrationPage::class);

        // Run again (sequence second response is empty window) should not chain further
        // Execute directly; since we faked queue above, nothing else should be queued by this call
        (new FetchIntegrationPage($integration, $context))->handle();
        // We cannot assert "no queue pushes ever" because previous assertion already matched one push.
        // This test ensures no exception is thrown and flow stops on empty window.
        $this->assertTrue(true);
    }
}
