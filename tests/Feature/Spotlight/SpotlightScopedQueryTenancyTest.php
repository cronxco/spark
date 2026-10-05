<?php

namespace Tests\Feature\Spotlight;

use App\Models\Block;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\MetricStatistic;
use App\Models\MetricTrend;
use App\Models\User;
use App\Spotlight\Queries\Integration\IntegrationSearchQuery;
use App\Spotlight\Queries\Scoped\AccountEventsQuery;
use App\Spotlight\Queries\Scoped\AccountIntegrationQuery;
use App\Spotlight\Queries\Scoped\BlockEventQuery;
use App\Spotlight\Queries\Scoped\BlockRelatedBlocksQuery;
use App\Spotlight\Queries\Scoped\EventActorQuery;
use App\Spotlight\Queries\Scoped\EventBlocksQuery;
use App\Spotlight\Queries\Scoped\EventIntegrationQuery;
use App\Spotlight\Queries\Scoped\EventTargetQuery;
use App\Spotlight\Queries\Scoped\IntegrationBlocksQuery;
use App\Spotlight\Queries\Scoped\IntegrationObjectsQuery;
use App\Spotlight\Queries\Scoped\MetricAnomaliesQuery;
use App\Spotlight\Queries\Scoped\MetricEventsQuery;
use App\Spotlight\Queries\Scoped\ObjectEventsQuery;
use App\Spotlight\Queries\Scoped\ObjectIntegrationQuery;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionObject;
use Tests\TestCase;

/**
 * CC-01 residual.
 *
 * spark#1077 scoped the palette's top-level searches, but the token-scoped
 * queries (the ones that run once an event, block, object, account,
 * integration or metric is selected) still resolved the token's id with a bare
 * `find()`. Token parameters come from the client, so a forged id listed
 * another user's actors, blocks, events, integrations and trends.
 */
class SpotlightScopedQueryTenancyTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create();
        $this->bob = User::factory()->create();
    }

    #[Test]
    public function event_token_queries_ignore_another_users_event(): void
    {
        $bobsEvent = $this->eventFor($this->bob);
        Block::factory()->create(['event_id' => $bobsEvent->id]);

        $this->actingAs($this->alice);

        foreach ([EventActorQuery::class, EventTargetQuery::class, EventIntegrationQuery::class, EventBlocksQuery::class] as $query) {
            $this->assertCount(0, $this->runWithToken($query, $bobsEvent->id), $query);
        }
    }

    #[Test]
    public function event_token_queries_still_resolve_your_own_event(): void
    {
        $event = $this->eventFor($this->alice);

        $this->actingAs($this->alice);

        $this->assertCount(1, $this->runWithToken(EventActorQuery::class, $event->id));
        $this->assertCount(1, $this->runWithToken(EventIntegrationQuery::class, $event->id));
    }

    #[Test]
    public function block_token_queries_ignore_another_users_block(): void
    {
        $bobsEvent = $this->eventFor($this->bob);
        $bobsBlock = Block::factory()->create(['event_id' => $bobsEvent->id]);
        Block::factory()->create(['event_id' => $bobsEvent->id]);

        $this->actingAs($this->alice);

        $this->assertCount(0, $this->runWithToken(BlockEventQuery::class, $bobsBlock->id));
        $this->assertCount(0, $this->runWithToken(BlockRelatedBlocksQuery::class, $bobsBlock->id));
    }

    #[Test]
    public function object_and_account_token_queries_ignore_another_users_object(): void
    {
        $bobsEvent = $this->eventFor($this->bob);
        $bobsObject = $bobsEvent->actor;
        $bobsObject->update(['metadata' => ['service' => 'monzo']]);

        $this->actingAs($this->alice);

        foreach ([ObjectEventsQuery::class, ObjectIntegrationQuery::class, AccountEventsQuery::class, AccountIntegrationQuery::class] as $query) {
            $this->assertCount(0, $this->runWithToken($query, $bobsObject->id), $query);
        }
    }

    #[Test]
    public function integration_token_queries_ignore_another_users_integration(): void
    {
        $bobsEvent = $this->eventFor($this->bob);
        Block::factory()->create(['event_id' => $bobsEvent->id]);

        $this->actingAs($this->alice);

        $this->assertCount(0, $this->runWithToken(IntegrationBlocksQuery::class, $bobsEvent->integration_id));
        $this->assertCount(0, $this->runWithToken(IntegrationObjectsQuery::class, $bobsEvent->integration_id));
    }

    #[Test]
    public function metric_token_queries_ignore_another_users_metric(): void
    {
        $bobsMetric = MetricStatistic::factory()->create(['user_id' => $this->bob->id]);
        MetricTrend::factory()->create(['metric_statistic_id' => $bobsMetric->id]);

        $this->actingAs($this->alice);

        $this->assertCount(0, $this->runWithToken(MetricAnomaliesQuery::class, $bobsMetric->id));
        $this->assertCount(0, $this->runWithToken(MetricEventsQuery::class, $bobsMetric->id));
    }

    #[Test]
    public function metric_events_only_lists_your_own_events_for_a_shared_metric_name(): void
    {
        $alicesMetric = MetricStatistic::factory()->create([
            'user_id' => $this->alice->id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
        ]);
        Event::factory()->create([
            'integration_id' => $this->integrationFor($this->bob)->id,
            'service' => 'oura',
            'action' => 'had_readiness_score',
        ]);

        $this->actingAs($this->alice);

        $this->assertCount(0, $this->runWithToken(MetricEventsQuery::class, $alicesMetric->id));
    }

    #[Test]
    public function integration_search_excludes_another_users_integrations(): void
    {
        $group = IntegrationGroup::factory()->create(['user_id' => $this->bob->id, 'service' => 'spotify']);
        Integration::factory()->create([
            'user_id' => $this->bob->id,
            'integration_group_id' => $group->id,
            'service' => 'spotify',
            'name' => 'zzz secret integration',
        ]);

        $this->actingAs($this->alice);

        $results = ($this->callable(IntegrationSearchQuery::make()))('zzz secret');

        $this->assertCount(0, $results);
    }

    private function eventFor(User $user): Event
    {
        return Event::factory()->create(['integration_id' => $this->integrationFor($user)->id])->fresh(['actor', 'integration']);
    }

    private function integrationFor(User $user): Integration
    {
        return Integration::factory()->create(['user_id' => $user->id]);
    }

    /**
     * Run a token-scoped query with a token carrying the given id, as the
     * palette would after the client sends it back.
     */
    private function runWithToken(string $queryClass, string $id): iterable
    {
        $token = new class($id)
        {
            public function __construct(private string $id) {}

            public function getParameter(string $key): ?string
            {
                return $key === 'id' ? $this->id : null;
            }
        };

        return ($this->callable($queryClass::make()))('', $token);
    }

    /**
     * Extract the closure a SpotlightQuery wraps so it can be invoked directly
     * without booting the palette component.
     */
    private function callable(object $spotlightQuery): callable
    {
        foreach (['getQuery', 'query', 'getCallback'] as $accessor) {
            if (method_exists($spotlightQuery, $accessor)) {
                return $spotlightQuery->{$accessor}();
            }
        }

        $reflection = new ReflectionObject($spotlightQuery);

        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($spotlightQuery);

            if ($value instanceof Closure) {
                return $value;
            }
        }

        $this->fail('Could not extract the query closure from ' . $spotlightQuery::class);
    }
}
