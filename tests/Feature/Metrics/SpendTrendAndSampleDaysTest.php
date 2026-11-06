<?php

namespace Tests\Feature\Metrics;

use App\Models\Event;
use App\Models\Integration;
use App\Models\MetricStatistic;
use App\Models\User;
use App\Services\Metrics\SpendSeries;
use App\Services\Mobile\MetricTrendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpendTrendAndSampleDaysTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function sample_days_never_exceeds_the_detection_window(): void
    {
        $statistic = MetricStatistic::factory()->create(['event_count' => 4402]);

        $this->assertSame(90, $statistic->sampleDays());

        $statistic->event_count = 40;
        $this->assertSame(40, $statistic->sampleDays());
    }

    #[Test]
    public function the_trend_of_spend_has_a_value_for_every_day_with_card_payments_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-06 15:00:00', 'UTC'));

        $user = User::factory()->create();
        $integration = Integration::factory()->create(['user_id' => $user->id, 'service' => 'monzo']);
        SpendSeries::statisticFor($user);

        $pay = fn (string $action, float $amount, string $day) => Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => $action,
            'value' => (int) round($amount * 100),
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
            'time' => Carbon::parse($day . ' 12:00:00', 'UTC'),
            'event_metadata' => [],
        ]);

        $pay('card_payment_to', 12.50, '2026-11-03');
        $pay('card_payment_to', 7.50, '2026-11-03');
        $pay('direct_debit_to', 1500.0, '2026-11-04');
        $pay('card_payment_to', 30.0, '2026-11-05');

        $trend = app(MetricTrendService::class)->trend($user, 'spend.spent.GBP', '2026-11-02', '2026-11-06');

        $this->assertNotNull($trend);
        $byDate = collect($trend['daily_values'])->pluck('value', 'date');

        $this->assertEqualsWithDelta(20.0, $byDate['2026-11-03'], 0.001);
        $this->assertEqualsWithDelta(0.0, $byDate['2026-11-04'], 0.001);
        $this->assertEqualsWithDelta(30.0, $byDate['2026-11-05'], 0.001);
    }
}
