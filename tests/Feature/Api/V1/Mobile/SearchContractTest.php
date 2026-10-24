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
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
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
    public function results_are_tenant_scoped(): void
    {
        $other = User::factory()->create();
        EventObject::factory()->create(['user_id' => $other->id, 'title' => 'coffee secrets']);
        Place::factory()->create(['user_id' => $other->id, 'title' => 'Coffee secret place']);

        $response = $this->getJson('/api/v1/mobile/search?contract_version=1&query=coffee')->assertOk()->json();

        $this->assertSame([], $response['results']);
    }

    #[Test]
    public function legacy_alias_use_is_counted_without_query_text(): void
    {
        $this->getJson('/api/v1/mobile/search?q=monzo&mode=integrations')->assertOk();
        $this->getJson('/api/v1/mobile/search/objects?q=Tesco&semantic=false')->assertOk();

        $counts = LegacyAliasUsage::counts(now()->format('Y-m-d'));

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
