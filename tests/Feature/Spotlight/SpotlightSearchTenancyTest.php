<?php

namespace Tests\Feature\Spotlight;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\MetricStatistic;
use App\Models\User;
use App\Spotlight\Queries\Search\BlockSearchQuery;
use App\Spotlight\Queries\Search\EventSearchQuery;
use App\Spotlight\Queries\Search\FinancialAccountSearchQuery;
use App\Spotlight\Queries\Search\MetricSearchQuery;
use App\Spotlight\Queries\Search\ObjectSearchQuery;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionObject;
use Tests\TestCase;

/**
 * PSEC-02b / CC-01.
 *
 * The Spotlight palette's lexical queries carried no user predicate, so typing
 * three characters surfaced other tenants' event actions, object titles, block
 * titles, metric names and — via FinancialAccountSearchQuery — account names
 * with their balances. Semantic search and the mobile SearchDispatcher were
 * already correctly scoped; these are the queries that were not.
 */
class SpotlightSearchTenancyTest extends TestCase
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
    public function event_search_excludes_another_users_events(): void
    {
        Event::factory()->create([
            'integration_id' => $this->integrationFor($this->bob)->id,
            'action' => 'zzz_secret_action',
        ]);

        $this->actingAs($this->alice);

        $results = ($this->callable(EventSearchQuery::make()))('zzz_secret');

        $this->assertCount(0, $results);
    }

    #[Test]
    public function event_search_still_returns_your_own_events(): void
    {
        Event::factory()->create([
            'integration_id' => $this->integrationFor($this->alice)->id,
            'action' => 'zzz_secret_action',
        ]);

        $this->actingAs($this->alice);

        $results = ($this->callable(EventSearchQuery::make()))('zzz_secret');

        $this->assertCount(1, $results);
    }

    #[Test]
    public function object_search_excludes_another_users_objects(): void
    {
        EventObject::factory()->create([
            'user_id' => $this->bob->id,
            'title' => 'zzz_secret_object',
        ]);
        EventObject::factory()->create([
            'user_id' => $this->alice->id,
            'title' => 'zzz_secret_object',
        ]);

        $this->actingAs($this->alice);

        $results = ($this->callable(ObjectSearchQuery::make()))('zzz_secret');

        $this->assertCount(1, $results);
    }

    #[Test]
    public function block_search_excludes_another_users_blocks(): void
    {
        $bobsEvent = Event::factory()->create([
            'integration_id' => $this->integrationFor($this->bob)->id,
        ]);
        Block::factory()->create([
            'event_id' => $bobsEvent->id,
            'title' => 'zzz_secret_block',
        ]);

        $this->actingAs($this->alice);

        $results = ($this->callable(BlockSearchQuery::make()))('zzz_secret');

        $this->assertCount(0, $results);
    }

    #[Test]
    public function metric_search_excludes_another_users_metrics(): void
    {
        MetricStatistic::factory()->create([
            'user_id' => $this->bob->id,
            'service' => 'zzzsecret',
        ]);

        $this->actingAs($this->alice);

        $results = ($this->callable(MetricSearchQuery::make()))('zzzsecret');

        $this->assertCount(0, $results);
    }

    #[Test]
    public function financial_account_search_excludes_another_users_accounts(): void
    {
        EventObject::factory()->create([
            'user_id' => $this->bob->id,
            'concept' => 'account',
            'type' => 'monzo_account',
            'title' => 'zzz_secret_account',
        ]);

        $this->actingAs($this->alice);

        $results = ($this->callable(FinancialAccountSearchQuery::make()))('zzz_secret');

        $this->assertCount(0, $results);
    }

    private function integrationFor(User $user): Integration
    {
        return Integration::factory()->create(['user_id' => $user->id]);
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
