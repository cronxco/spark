<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Helpers\MetricIdentifierMap;
use App\Models\MetricStatistic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetricIdentifierMapTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    #[Test]
    public function resolves_exact_canonical_identifier(): void
    {
        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'percent',
        ]);

        $result = MetricIdentifierMap::resolve('oura.had_sleep_score.percent', $this->user);

        $this->assertNotNull($result);
        $this->assertEquals('oura', $result->service);
        $this->assertEquals('had_sleep_score', $result->action);
        $this->assertEquals('percent', $result->value_unit);
    }

    #[Test]
    public function resolves_without_had_prefix(): void
    {
        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'percent',
        ]);

        $result = MetricIdentifierMap::resolve('oura.sleep_score', $this->user);

        $this->assertNotNull($result);
        $this->assertEquals('had_sleep_score', $result->action);
    }

    #[Test]
    public function resolves_without_had_prefix_but_with_unit(): void
    {
        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'percent',
        ]);

        $result = MetricIdentifierMap::resolve('oura.sleep_score.percent', $this->user);

        $this->assertNotNull($result);
        $this->assertEquals('had_sleep_score', $result->action);
        $this->assertEquals('percent', $result->value_unit);
    }

    #[Test]
    public function resolves_with_had_prefix_but_without_unit(): void
    {
        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'percent',
        ]);

        $result = MetricIdentifierMap::resolve('oura.had_sleep_score', $this->user);

        $this->assertNotNull($result);
        $this->assertEquals('had_sleep_score', $result->action);
        $this->assertEquals('percent', $result->value_unit);
    }

    #[Test]
    public function resolves_action_without_had_prefix(): void
    {
        // Actions like "slept_for" or "did_workout" don't start with had_
        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'slept_for',
            'value_unit' => 'seconds',
        ]);

        $result = MetricIdentifierMap::resolve('oura.slept_for', $this->user);

        $this->assertNotNull($result);
        $this->assertEquals('slept_for', $result->action);
    }

    #[Test]
    public function returns_null_for_unknown_identifier(): void
    {
        $result = MetricIdentifierMap::resolve('unknown.metric', $this->user);

        $this->assertNull($result);
    }

    #[Test]
    public function returns_null_when_ambiguous_without_unit(): void
    {
        // Same service+action but different units
        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'receipt',
            'action' => 'had_receipt_from',
            'value_unit' => 'GBP',
        ]);

        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'receipt',
            'action' => 'had_receipt_from',
            'value_unit' => 'USD',
        ]);

        $result = MetricIdentifierMap::resolve('receipt.receipt_from', $this->user);

        $this->assertNull($result);
    }

    #[Test]
    public function resolves_ambiguous_when_unit_provided(): void
    {
        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'receipt',
            'action' => 'had_receipt_from',
            'value_unit' => 'GBP',
        ]);

        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'receipt',
            'action' => 'had_receipt_from',
            'value_unit' => 'USD',
        ]);

        $result = MetricIdentifierMap::resolve('receipt.receipt_from.GBP', $this->user);

        $this->assertNotNull($result);
        $this->assertEquals('GBP', $result->value_unit);
    }

    #[Test]
    public function resolves_many_identifiers(): void
    {
        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'percent',
        ]);

        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'apple_health',
            'action' => 'had_step_count',
            'value_unit' => 'count',
        ]);

        $result = MetricIdentifierMap::resolveMany([
            'oura.sleep_score',
            'apple_health.step_count',
            'unknown.metric',
        ], $this->user);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('oura.sleep_score', $result);
        $this->assertArrayHasKey('apple_health.step_count', $result);
    }

    #[Test]
    public function lists_available_identifiers(): void
    {
        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'percent',
        ]);

        $identifiers = MetricIdentifierMap::availableIdentifiers($this->user);

        $this->assertNotEmpty($identifiers);
        $this->assertContains('oura.had_sleep_score.percent', $identifiers);
    }

    #[Test]
    public function scopes_to_user(): void
    {
        $otherUser = User::factory()->create();

        MetricStatistic::factory()->create([
            'user_id' => $otherUser->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'percent',
        ]);

        $result = MetricIdentifierMap::resolve('oura.sleep_score', $this->user);

        $this->assertNull($result);
    }

    #[Test]
    public function available_for_service_shows_helpful_hint(): void
    {
        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'percent',
        ]);

        $hint = MetricIdentifierMap::availableForService('oura', $this->user);

        $this->assertStringContainsString('oura.had_sleep_score.percent', $hint);
    }
}
