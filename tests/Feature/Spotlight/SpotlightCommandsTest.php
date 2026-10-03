<?php

namespace Tests\Feature\Spotlight;

use App\Integrations\Spotify\SpotifyPlugin;
use App\Jobs\OAuth\Spotify\SpotifyListeningPull;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Spotlight\Queries\Actions\GlobalActionsQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CC-03.
 *
 * Several Spotlight commands dispatched browser events nothing listened for,
 * so choosing them did nothing. These pin each visible command to a handler.
 */
class SpotlightCommandsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function trigger_all_integrations_fetches_your_unpaused_integrations_only(): void
    {
        Queue::fake();

        $alice = User::factory()->create();
        $active = $this->spotifyIntegrationFor($alice);
        $paused = $this->spotifyIntegrationFor($alice, ['configuration' => ['paused' => true]]);
        $bobs = $this->spotifyIntegrationFor(User::factory()->create());

        Volt::actingAs($alice)
            ->test('global-progress-indicator')
            ->dispatch('trigger-all-integrations')
            ->assertHasNoErrors();

        Queue::assertPushed(SpotifyListeningPull::class, 1);
        Queue::assertPushed(SpotifyListeningPull::class, fn ($job) => $this->integrationOf($job)?->is($active));
        Queue::assertNotPushed(SpotifyListeningPull::class, fn ($job) => $this->integrationOf($job)?->is($paused) || $this->integrationOf($job)?->is($bobs));
    }

    #[Test]
    public function trigger_all_integrations_can_be_limited_to_one_service(): void
    {
        Queue::fake();

        $alice = User::factory()->create();
        $this->spotifyIntegrationFor($alice);

        Volt::actingAs($alice)
            ->test('global-progress-indicator')
            ->dispatch('trigger-all-integrations', service: 'oura');

        Queue::assertNotPushed(SpotifyListeningPull::class);

        Volt::actingAs($alice)
            ->test('global-progress-indicator')
            ->dispatch('trigger-all-integrations', service: 'spotify');

        Queue::assertPushed(SpotifyListeningPull::class, 1);
    }

    #[Test]
    public function the_spotify_sync_command_uses_the_integration_trigger(): void
    {
        $command = SpotifyPlugin::getSpotlightCommands()['spotify-sync-recent'];

        $this->assertSame('trigger-all-integrations', $command['actionParams']['name']);
        $this->assertSame(['service' => 'spotify'], $command['actionParams']['data']);
    }

    #[Test]
    public function global_commands_only_dispatch_events_that_have_a_global_listener(): void
    {
        $globallyHandled = ['trigger-all-integrations', 'run-flint-routine'];
        $events = collect(GlobalActionsQuery::actions())->pluck('event')->filter();

        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $this->assertContains($event, $globallyHandled);
        }
    }

    private function spotifyIntegrationFor(User $user, array $attributes = []): Integration
    {
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'spotify']);

        return Integration::factory()->create(array_merge([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'spotify',
        ], $attributes));
    }

    private function integrationOf(object $job): ?Integration
    {
        return $job->getIntegration();
    }
}
