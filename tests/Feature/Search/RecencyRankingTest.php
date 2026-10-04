<?php

namespace Tests\Feature\Search;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use App\Services\Ai\EmbeddingClient;
use App\Services\Mobile\SearchDispatcher;
use App\Services\Search\RecencyRanking;
use App\Spotlight\Queries\Search\BlockSearchQuery;
use App\Spotlight\Queries\Search\EventSearchQuery;
use App\Spotlight\Queries\Search\ObjectSearchQuery;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use ReflectionObject;
use Tests\Support\MobileSessionAbilities;
use Tests\TestCase;

/**
 * C-7: recency is one tunable signal in the ranking Spotlight and the mobile
 * search API share, set by `spark.search.recency`.
 */
class RecencyRankingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-03 12:00:00');
        config(['ios.mobile_api_enabled' => true]);

        $this->user = User::factory()->create();
        $this->integration = Integration::factory()->create(['user_id' => $this->user->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function a_recent_object_ranks_above_an_older_equally_good_match_when_weight_is_positive(): void
    {
        $old = $this->object('Tesco Express', now()->subYears(2));
        $recent = $this->object('Tesco Metro', now()->subDay());

        $titles = $this->search(new RecencyRanking(0.2, 30), 'Tesco')['objects']->pluck('id')->all();

        $this->assertSame([$recent->id, $old->id], $titles);
    }

    #[Test]
    public function weight_zero_orders_by_relevance_alone_whatever_the_dates(): void
    {
        $exact = $this->object('Tesco', now()->subYears(3));
        $prefix = $this->object('Tesco Metro', now()->subYear());
        $contains = $this->object('Big Tesco', now());

        $ids = $this->search(new RecencyRanking(0, 30), 'Tesco')['objects']->pluck('id')->all();

        $this->assertSame([$exact->id, $prefix->id, $contains->id], $ids);
    }

    #[Test]
    public function a_stronger_weight_lets_recency_beat_a_better_match(): void
    {
        $oldExact = $this->object('Tesco', now()->subYear());
        $newContains = $this->object('Big Tesco', now());

        $gentle = $this->search(new RecencyRanking(0.2, 30), 'Tesco')['objects']->pluck('id')->all();
        $strong = $this->search(new RecencyRanking(0.5, 30), 'Tesco')['objects']->pluck('id')->all();

        $this->assertSame([$oldExact->id, $newContains->id], $gentle);
        $this->assertSame([$newContains->id, $oldExact->id], $strong);
    }

    #[Test]
    public function the_mobile_search_endpoint_follows_the_configured_weight(): void
    {
        $old = $this->object('Tesco Express', now()->subYears(2));
        $recent = $this->object('Tesco Metro', now()->subDay());
        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read', 'ios:write']));

        config(['spark.search.recency.weight' => 0.2]);
        $this->getJson('/api/v1/mobile/search?q=Tesco')
            ->assertOk()
            ->assertJsonPath('objects.0.id', $recent->id);

        config(['spark.search.recency.weight' => 0]);
        $this->getJson('/api/v1/mobile/search?q=Tesco%20Express')
            ->assertOk()
            ->assertJsonPath('objects.0.id', $old->id);
    }

    #[Test]
    public function the_mobile_typed_search_endpoint_uses_the_same_ranking(): void
    {
        $old = $this->object('Tesco Express', now()->subYears(2));
        $recent = $this->object('Tesco Metro', now()->subDay());
        Sanctum::actingAs($this->user, MobileSessionAbilities::with(['ios:read', 'ios:write']));

        $this->getJson('/api/v1/mobile/search/objects?q=Tesco&semantic=false')
            ->assertOk()
            ->assertJsonPath('objects.0.id', $recent->id)
            ->assertJsonPath('objects.1.id', $old->id);
    }

    #[Test]
    public function semantic_search_blends_similarity_with_recency(): void
    {
        $vector = array_fill(0, 1536, 0.1);
        $close = $vector;
        $close[0] = 0.12;
        $old = $this->object('Old run', now()->subYear());
        $recent = $this->object('Recent run', now()->subDay());
        $old->forceFill(['embeddings' => EmbeddingClient::formatForPostgres($vector)])->saveQuietly();
        $recent->forceFill(['embeddings' => EmbeddingClient::formatForPostgres($close)])->saveQuietly();

        $embeddings = $this->mock(EmbeddingClient::class);
        $embeddings->shouldReceive('embed')->andReturn($vector);

        $off = (new SearchDispatcher($embeddings, new RecencyRanking(0, 30)))->search($this->user, 'semantic', 'run');
        $on = (new SearchDispatcher($embeddings, new RecencyRanking(0.2, 30)))->search($this->user, 'semantic', 'run');

        $this->assertSame([$old->id, $recent->id], $off['objects']->pluck('id')->all());
        $this->assertSame([$recent->id, $old->id], $on['objects']->pluck('id')->all());
        $this->assertEqualsWithDelta(1, (float) $on['objects']->first()->days_ago, 0.01);
    }

    #[Test]
    public function spotlight_text_searches_order_through_the_shared_ranking(): void
    {
        $this->event('listened_to_track', now()->subYear());
        $this->event('listened_to_track', now()->subHour());
        $this->object('Tesco Metro', now()->subDay());
        $event = $this->event('card_payment_to', now());
        Block::factory()->create(['event_id' => $event->id, 'title' => 'Tesco basket', 'time' => now()->subDay()]);

        $ranking = new class(0.2, 30) extends RecencyRanking
        {
            /** @var array<int, string> */
            public array $orderedBy = [];

            public function orderByText(Builder $query, string $term, string $primaryColumn, string $timeColumn = 'time'): Builder
            {
                $this->orderedBy[] = $query->getModel()->getTable() . '.' . $primaryColumn;

                return parent::orderByText($query, $term, $primaryColumn, $timeColumn);
            }
        };
        $this->app->instance(RecencyRanking::class, $ranking);
        $this->actingAs($this->user);

        $this->assertCount(2, ($this->callable(EventSearchQuery::make()))('listened'));
        $this->assertCount(1, ($this->callable(ObjectSearchQuery::make()))('Tesco'));
        $this->assertCount(1, ($this->callable(BlockSearchQuery::make()))('Tesco'));
        $this->assertSame(['events.action', 'objects.title', 'blocks.title'], $ranking->orderedBy);
    }

    private function search(RecencyRanking $ranking, string $query): array
    {
        return (new SearchDispatcher(null, $ranking))->search($this->user, 'default', $query);
    }

    private function object(string $title, Carbon $time): EventObject
    {
        return EventObject::factory()->create(['user_id' => $this->user->id, 'title' => $title, 'content' => null, 'time' => $time]);
    }

    private function event(string $action, Carbon $time): Event
    {
        return Event::factory()->create(['integration_id' => $this->integration->id, 'action' => $action, 'service' => 'spotify', 'time' => $time]);
    }

    /**
     * Extract the closure a SpotlightQuery wraps so it can be invoked directly.
     */
    private function callable(object $spotlightQuery): callable
    {
        foreach (['getQuery', 'query', 'getCallback'] as $accessor) {
            if (method_exists($spotlightQuery, $accessor)) {
                return $spotlightQuery->{$accessor}();
            }
        }

        foreach ((new ReflectionObject($spotlightQuery))->getProperties() as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($spotlightQuery);

            if ($value instanceof Closure) {
                return $value;
            }
        }

        $this->fail('Could not extract the query closure from ' . $spotlightQuery::class);
    }
}
