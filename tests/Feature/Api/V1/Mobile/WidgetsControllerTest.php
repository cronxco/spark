<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\MetricStatistic;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WidgetsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Integration $monzo;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);

        $this->user = User::factory()->create();

        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'monzo',
        ]);

        $this->monzo = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
        ]);
    }

    #[Test]
    public function today_requires_authentication(): void
    {
        $this->getJson('/api/v1/mobile/widgets/today')->assertStatus(401);
    }

    #[Test]
    public function today_returns_widget_shape_and_stays_under_4kb(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $response = $this->getJson('/api/v1/mobile/widgets/today')
            ->assertOk()
            ->assertJsonStructure(['date', 'metrics', 'generated_at']);

        $this->assertLessThan(4096, strlen($response->getContent() ?: ''));
    }

    #[Test]
    public function metric_widget_returns_sparkline_for_known_metric(): void
    {
        MetricStatistic::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'value_unit' => 'percent',
            'mean_value' => 80,
            'stddev_value' => 5,
            'normal_lower_bound' => 70,
            'normal_upper_bound' => 90,
            'event_count' => 100,
        ]);

        $ouraGroup = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'oura',
        ]);
        $oura = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $ouraGroup->id,
            'service' => 'oura',
        ]);

        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create(['user_id' => $this->user->id]);

        foreach (range(0, 6) as $offset) {
            Event::factory()->create([
                'integration_id' => $oura->id,
                'service' => 'oura',
                'action' => 'had_sleep_score',
                'value' => 80 + $offset,
                'value_multiplier' => 1,
                'value_unit' => 'percent',
                'time' => Carbon::today()->subDays($offset),
                'actor_id' => $actor->id,
                'target_id' => $target->id,
            ]);
        }

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/widgets/metrics/oura.sleep_score')
            ->assertOk()
            ->assertJsonStructure(['metric', 'value', 'unit', 'sparkline']);
    }

    #[Test]
    public function metric_widget_returns_404_for_unknown_metric(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/widgets/metrics/bogus.metric')
            ->assertStatus(404);
    }

    #[Test]
    public function spend_widget_returns_total_and_top_merchants(): void
    {
        $this->createMonzoEvent('Tesco', 1250);
        $this->createMonzoEvent('Tesco', 500);
        $this->createMonzoEvent('Greggs', 320);
        $this->createMonzoEvent('Shell', 6000);

        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $response = $this->getJson('/api/v1/mobile/widgets/spend')
            ->assertOk()
            ->assertJsonStructure([
                'date', 'total', 'unit', 'transaction_count',
                'top_merchants' => [['name', 'amount']],
            ]);

        $data = $response->json();

        $this->assertEquals('GBP', $data['unit']);
        $this->assertEqualsWithDelta(80.70, $data['total'], 0.01);
        $this->assertCount(3, $data['top_merchants']);
        $this->assertEquals('Shell', $data['top_merchants'][0]['name']);
    }

    #[Test]
    public function spend_widget_requires_authentication(): void
    {
        $this->getJson('/api/v1/mobile/widgets/spend')->assertStatus(401);
    }

    protected function createMonzoEvent(string $merchant, int $pennies): Event
    {
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);
        $target = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'title' => $merchant,
        ]);

        return Event::factory()->create([
            'integration_id' => $this->monzo->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'card_payment_to',
            'value' => $pennies,
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
            'time' => Carbon::today()->setHour(12),
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);
    }
}
