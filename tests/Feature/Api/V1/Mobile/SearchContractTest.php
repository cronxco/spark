<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\MetricStatistic;
use App\Models\Place;
use App\Models\User;
use App\Services\Actions\ActionRegistry;
use App\Services\Ai\EmbeddingClient;
use App\Services\Mobile\SearchContract;
use App\Services\Search\LegacyAliasUsage;
use App\Support\SparkAbility;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Tags\Tag;
use Tests\TestCase;

/**
 * Pins search contract v1 (S1, S2, S3, S4, Q1). The fixtures under
 * tests/Fixtures/search-contract/v1 are shared with spark-ios.
 */
class SearchContractTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);

        $this->user = User::factory()->create();

        $group = IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => 'monzo']);

        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
            'name' => 'coffee monzo',
        ]);

        Sanctum::actingAs($this->user, SparkAbility::MOBILE_SESSION);
    }

    #[Test]
    public function fixtures_only_use_kinds_the_contract_declares(): void
    {
        foreach (['complete', 'degraded', 'empty'] as $name) {
            $fixture = $this->fixture($name);

            $this->assertSame(SearchContract::VERSION, $fixture['schema_version']);
            $this->assertContains($fixture['execution']['status'], [SearchContract::STATUS_COMPLETE, SearchContract::STATUS_DEGRADED]);

            foreach ($fixture['results'] as $hit) {
                $this->assertContains($hit['kind'], SearchContract::KINDS, "{$name}.json");
            }
        }

        $this->assertEqualsCanonicalizing(
            SearchContract::KINDS,
            collect($this->fixture('complete')['results'])->pluck('kind')->all(),
            'complete.json must show every kind v1 can return.',
        );
    }

    #[Test]
    public function live_response_matches_the_fixture_shape_for_every_kind(): void
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id, 'title' => 'coffee shop', 'concept' => 'merchant']);

        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'coffee_payment_to',
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);
        $event->attachTag('coffee');

        Block::factory()->create(['event_id' => $event->id, 'block_type' => 'summary', 'title' => 'Coffee spend']);
        Place::factory()->create(['user_id' => $this->user->id, 'title' => 'Coffee House']);
        MetricStatistic::factory()->create(['user_id' => $this->user->id, 'service' => 'coffee', 'action' => 'had_coffee_count']);

        $response = $this->getJson('/api/v1/mobile/search?contract_version=1&query=coffee')->assertOk()->json();
        $fixture = $this->fixture('complete');

        $this->assertSame(array_keys($fixture), array_keys($response));
        $this->assertSame(array_keys($fixture['execution']), array_keys($response['execution']));
        $this->assertSame(SearchContract::VERSION, $response['schema_version']);
        $this->assertSame('coffee', $response['query']);
        $this->assertSame(SearchContract::STATUS_COMPLETE, $response['execution']['status']);
        $this->assertNull($response['execution']['fallback_reason']);
        $this->assertSame($response['meta']['count'], count($response['results']));

        foreach (array_diff(SearchContract::KINDS, ['command']) as $kind) {
            $this->assertSame($this->keysOfKind($fixture, $kind), $this->keysOfKind($response, $kind), "Keys drifted for kind {$kind}.");
        }

        $this->assertSame(
            1,
            collect($response['results'])->where('kind', 'object')->where('id', (string) $target->id)->count(),
            'The target object is listed once, and a place is not also listed as an object.',
        );
    }

    #[Test]
    public function commands_are_the_eighth_kind_and_match_the_registry(): void
    {
        $fixture = $this->fixture('complete');

        $all = $this->getJson('/api/v1/mobile/search?contract_version=1&query=sync')->assertOk()->json();
        $this->assertSame($this->keysOfKind($fixture, 'command'), $this->keysOfKind($all, 'command'), 'Keys drifted for kind command.');

        $only = $this->getJson('/api/v1/mobile/search?contract_version=1&query=sync&mode=command')->assertOk()->json();
        $this->assertNotEmpty($only['results']);
        $this->assertSame(['command'], collect($only['results'])->pluck('kind')->unique()->values()->all());

        $registry = app(ActionRegistry::class);
        foreach ($only['results'] as $hit) {
            $definition = $registry->find($hit['id']);
            $this->assertNotNull($definition, "{$hit['id']} is not in the registry.");
            $this->assertTrue($definition->on('native'));
            $this->assertSame($definition->kind->value, $hit['action_kind']);
            $this->assertSame($definition->confirm->value, $hit['confirm']);
        }
    }

    #[Test]
    public function empty_query_returns_the_empty_fixture_shape(): void
    {
        $response = $this->getJson('/api/v1/mobile/search?contract_version=1&query=')->assertOk()->json();
        $fixture = $this->fixture('empty');

        $this->assertSame(array_keys($fixture), array_keys($response));
        $this->assertSame([], $response['results']);
        $this->assertSame(SearchContract::STATUS_COMPLETE, $response['execution']['status']);
    }

    #[Test]
    public function failed_embedding_is_reported_as_degraded_not_hidden(): void
    {
        EventObject::factory()->create(['user_id' => $this->user->id, 'title' => 'Quiet mornings']);

        $this->mock(EmbeddingClient::class, function ($mock) {
            $mock->shouldReceive('embed')->andThrow(new Exception('upstream down'));
        });

        $response = $this->getJson('/api/v1/mobile/search?contract_version=1&query=Quiet&mode=semantic')->assertOk()->json();

        $this->assertSame(array_keys($this->fixture('degraded')['execution']), array_keys($response['execution']));
        $this->assertSame(SearchContract::STATUS_DEGRADED, $response['execution']['status']);
        $this->assertSame('embedding_failed', $response['execution']['fallback_reason']);
        $this->assertNotEmpty($response['results'], 'Keyword matches still come back.');
    }

    #[Test]
    public function v1_rejects_the_legacy_spellings(): void
    {
        $this->getJson('/api/v1/mobile/search?contract_version=1&q=coffee')->assertStatus(422)->assertJsonValidationErrors('query');
        $this->getJson('/api/v1/mobile/search?contract_version=1&query=coffee&mode=tags')->assertStatus(422)->assertJsonValidationErrors('mode');
        $this->getJson('/api/v1/mobile/search?contract_version=2&query=coffee')->assertStatus(422)->assertJsonPath('supported_versions.0', 1);
        $this->getJson('/api/v1/mobile/search?contract_version=1&query=coffee&limit=500')->assertStatus(422)->assertJsonValidationErrors('limit');
    }

    #[Test]
    public function v1_rejects_q_even_when_query_is_present(): void
    {
        $this->getJson('/api/v1/mobile/search?contract_version=1&query=coffee&q=other')->assertStatus(422)->assertJsonValidationErrors('q');
        $this->getJson('/api/v1/mobile/search?contract_version=1&query=coffee&q=')->assertStatus(422)->assertJsonValidationErrors('q');
    }

    #[Test]
    public function metric_hits_respect_the_requested_limit(): void
    {
        foreach (['a', 'b', 'c'] as $suffix) {
            MetricStatistic::factory()->create(['user_id' => $this->user->id, 'service' => 'coffee', 'action' => "had_{$suffix}_count"]);
        }

        foreach (['default', 'metric'] as $mode) {
            $metrics = collect($this->getJson("/api/v1/mobile/search?contract_version=1&query=coffee&mode={$mode}&limit=1")->assertOk()->json('results'))
                ->where('kind', 'metric');

            $this->assertCount(1, $metrics, "mode={$mode}");
        }
    }

    #[Test]
    public function semantic_fallback_stays_within_events_and_objects(): void
    {
        EventObject::factory()->create(['user_id' => $this->user->id, 'title' => 'coffee beans']);

        $this->mock(EmbeddingClient::class, function ($mock) {
            $mock->shouldReceive('embed')->andThrow(new Exception('upstream down'));
        });

        $kinds = collect($this->getJson('/api/v1/mobile/search?contract_version=1&query=coffee&mode=semantic')->assertOk()->json('results'))
            ->pluck('kind')->unique()->values()->all();

        $this->assertSame(['object'], $kinds);
    }

    #[Test]
    public function tag_counts_include_tagged_blocks(): void
    {
        $event = Event::factory()->create(['integration_id' => $this->integration->id]);
        $block = Block::factory()->create(['event_id' => $event->id, 'block_type' => 'summary', 'title' => 'Plain']);
        $tag = Tag::findOrCreate('blockonly');
        DB::table('taggables')->insert(['tag_id' => $tag->id, 'taggable_type' => Block::class, 'taggable_id' => $block->id]);

        $hit = collect($this->getJson('/api/v1/mobile/search?contract_version=1&query=blockonly')->assertOk()->json('results'))
            ->firstWhere('kind', 'tag');

        $this->assertSame(1, $hit['count']);
    }

    #[Test]
    public function results_are_tenant_scoped(): void
    {
        $other = User::factory()->create();
        $otherGroup = IntegrationGroup::factory()->create(['user_id' => $other->id, 'service' => 'monzo']);
        $otherIntegration = Integration::factory()->create([
            'user_id' => $other->id,
            'integration_group_id' => $otherGroup->id,
            'service' => 'monzo',
            'name' => 'coffee other',
        ]);
        $otherObject = EventObject::factory()->create(['user_id' => $other->id, 'title' => 'coffee secrets']);
        $otherPlace = Place::factory()->create(['user_id' => $other->id, 'title' => 'Coffee secret place']);
        $otherEvent = Event::factory()->create([
            'integration_id' => $otherIntegration->id,
            'service' => 'monzo',
            'action' => 'coffee_payment_to',
            'actor_id' => $otherObject->id,
            'target_id' => $otherObject->id,
        ]);
        $otherBlock = Block::factory()->create(['event_id' => $otherEvent->id, 'block_type' => 'summary', 'title' => 'Coffee spend']);
        $otherMetric = MetricStatistic::factory()->create(['user_id' => $other->id, 'service' => 'coffee', 'action' => 'had_coffee_count']);
        $otherEvent->attachTag('coffee-secret');

        $response = $this->getJson('/api/v1/mobile/search?contract_version=1&query=coffee')->assertOk()->json();
        $ids = collect($response['results'])->pluck('id')->all();

        foreach ([$otherIntegration->id, $otherObject->id, $otherPlace->id, $otherEvent->id, $otherBlock->id] as $foreign) {
            $this->assertNotContains((string) $foreign, $ids, 'Another tenant\'s record leaked into search.');
        }
        $this->assertNotContains('coffee.coffee_count', $ids);
        $this->assertNotContains((string) $otherMetric->id, $ids);
        $this->assertSame([], collect($response['results'])->where('kind', 'tag')->all());

        $this->assertSame(
            [(string) $this->integration->id],
            collect($response['results'])->where('kind', 'integration')->pluck('id')->values()->all(),
            'Only the signed-in user\'s own integration matches.',
        );
    }

    #[Test]
    public function legacy_alias_use_is_counted_without_query_text(): void
    {
        $this->getJson('/api/v1/mobile/search?q=monzo&mode=integrations')->assertOk();
        $this->getJson('/api/v1/mobile/search/objects?q=Tesco&semantic=false')->assertOk();

        $counts = LegacyAliasUsage::counts(now()->format('Y-m-d'));

        $this->getJson('/api/v1/mobile/search?mode=default&query=ignored')->assertOk();

        $this->assertSame(2, LegacyAliasUsage::counts(now()->format('Y-m-d'))['q'], 'A request without q is not counted.');
        $this->assertSame(2, $counts['q']);
        $this->assertSame(1, $counts['plural_mode']);
        $this->assertSame(1, $counts['string_boolean']);
    }

    /** @return array<string, mixed> */
    protected function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/search-contract/v1/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<int, string> */
    protected function keysOfKind(array $response, string $kind): array
    {
        $hit = collect($response['results'])->firstWhere('kind', $kind);
        $this->assertNotNull($hit, "No {$kind} hit in response.");

        $keys = array_keys($hit);
        sort($keys);

        return $keys;
    }
}
