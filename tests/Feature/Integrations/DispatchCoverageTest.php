<?php

namespace Tests\Feature\Integrations;

use App\Actions\DispatchIntegrationFetchJobs;
use App\Integrations\PluginRegistry;
use App\Jobs\OAuth\BlueSky\BlueSkyBookmarksPull;
use App\Jobs\OAuth\BlueSky\BlueSkyLikesPull;
use App\Jobs\OAuth\BlueSky\BlueSkyRepostsPull;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * INT-02: every instance the scheduler polls must map to at least one fetch
 * job. BlueSky had no mapping, so after its initial import every scheduled and
 * manual update reported "triggered" and queued nothing.
 */
class DispatchCoverageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_polled_instance_type_dispatches_at_least_one_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $missing = [];

        $polled = PluginRegistry::getOAuthPlugins()->merge(PluginRegistry::getApiKeyPlugins());

        foreach ($polled as $service => $pluginClass) {
            foreach (array_keys($pluginClass::getInstanceTypes()) as $instanceType) {
                // Task subtypes run through RunIntegrationTask, which needs a
                // configured command; they are covered by RunIntegrationTaskTest.
                if ($instanceType === 'task') {
                    continue;
                }

                $integration = $this->integration($user, $service, $instanceType);

                if ((new DispatchIntegrationFetchJobs)->dispatch($integration) === 0) {
                    $missing[] = "{$service}/{$instanceType}";
                }
            }
        }

        $this->assertSame([], $missing, 'Polled instance types with no fetch job: ' . implode(', ', $missing));
    }

    #[Test]
    public function bluesky_activity_dispatches_its_three_pulls(): void
    {
        Queue::fake();
        $integration = $this->integration(User::factory()->create(), 'bluesky', 'activity');

        $this->assertSame(3, (new DispatchIntegrationFetchJobs)->dispatch($integration));

        Queue::assertPushed(BlueSkyBookmarksPull::class);
        Queue::assertPushed(BlueSkyLikesPull::class);
        Queue::assertPushed(BlueSkyRepostsPull::class);
    }

    #[Test]
    public function bluesky_honours_the_tracking_toggles(): void
    {
        Queue::fake();
        $integration = $this->integration(User::factory()->create(), 'bluesky', 'activity', [
            'track_bookmarks' => false,
            'track_reposts' => false,
        ]);

        $this->assertSame(1, (new DispatchIntegrationFetchJobs)->dispatch($integration));

        Queue::assertPushed(BlueSkyLikesPull::class);
        Queue::assertNotPushed(BlueSkyBookmarksPull::class);
        Queue::assertNotPushed(BlueSkyRepostsPull::class);
    }

    #[Test]
    public function an_unknown_instance_type_dispatches_nothing(): void
    {
        Queue::fake();
        $integration = $this->integration(User::factory()->create(), 'github', 'not_a_type');

        $this->assertSame(0, (new DispatchIntegrationFetchJobs)->dispatch($integration));
        Queue::assertNothingPushed();
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function integration(User $user, string $service, string $instanceType, array $configuration = []): Integration
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => $service,
            'access_token' => 'token',
        ]);

        return Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => $service,
            'instance_type' => $instanceType,
            'configuration' => $configuration,
        ]);
    }
}
