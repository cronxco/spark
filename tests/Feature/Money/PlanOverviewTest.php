<?php

namespace Tests\Feature\Money;

use App\Livewire\Money\PlanOverview as PlanOverviewComponent;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\Money\PlanOverview;
use App\Services\Money\PotInterestInference;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlanOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.enable_task_pipeline' => false]);
        Livewire::withoutLazyLoading();
        $this->user = User::factory()->create();
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => 'monzo'])->id,
            'service' => 'monzo',
            'instance_type' => 'accounts',
        ]);
    }

    #[Test]
    public function it_summarises_pots_and_the_interest_ledger(): void
    {
        $big = $this->pot('Gift', ['pot_id' => 'p1', 'monzo' => ['goal_amount' => 20_000_000]]);
        $small = $this->pot('Holiday', ['pot_id' => 'p2']);
        $this->snapshots($big, [10_000_000, 10_029_200]);
        $this->snapshots($small, [100_000, 100_290]);
        app(PotInterestInference::class)->apply($this->user->id);

        $overview = app(PlanOverview::class)->build($this->user->id, CarbonImmutable::parse('2026-10-05'));

        $this->assertSame(10_129_490, $overview['strip']['in_pots']);
        $this->assertSame(29_490, $overview['strip']['latest_interest']);
        $this->assertSame(29_490 * 12, $overview['strip']['run_rate']);
        $this->assertSame((int) round(29_490 * 12 * 0.4), $overview['strip']['tax_estimate']);
        $this->assertSame('2026-09', $overview['strip']['latest_month']);

        $this->assertSame(['Gift', 'Holiday'], array_column($overview['pots'], 'name'));
        $this->assertSame(20_000_000, $overview['pots'][0]['goal']);
        $this->assertSame(29_200, $overview['pots'][0]['interest']);
        $this->assertSame(['Gift', 'Holiday'], array_column($overview['ledger'][0]['lines'], 'pot'));
        $this->assertFalse($overview['ledger'][0]['estimated']);
    }

    #[Test]
    public function it_is_empty_and_safe_before_any_interest_is_recorded(): void
    {
        $overview = app(PlanOverview::class)->build($this->user->id);

        $this->assertSame([], $overview['ledger']);
        $this->assertSame([], $overview['pots']);
        $this->assertSame(0, $overview['strip']['run_rate']);
        $this->assertNull($overview['strip']['latest_month']);
    }

    #[Test]
    public function it_only_shows_the_signed_in_users_figures(): void
    {
        $pot = $this->pot('Mine', ['pot_id' => 'p1']);
        $this->snapshots($pot, [1_000_000, 1_002_900]);
        app(PotInterestInference::class)->apply($this->user->id);

        $stranger = User::factory()->create();

        $this->assertSame([], app(PlanOverview::class)->build($stranger->id)['ledger']);
    }

    #[Test]
    public function the_component_renders_the_ledger(): void
    {
        $pot = $this->pot('Gift', ['pot_id' => 'p1']);
        $this->snapshots($pot, [10_000_000, 10_029_200]);
        app(PotInterestInference::class)->apply($this->user->id);

        Livewire::actingAs($this->user)->test(PlanOverviewComponent::class)
            ->assertSee('Interest ledger')
            ->assertSee('September 2026')
            ->assertSee('£292')
            ->assertSee('Gift');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function pot(string $title, array $metadata): EventObject
    {
        return EventObject::create([
            'user_id' => $this->user->id, 'concept' => 'account', 'type' => 'monzo_pot', 'title' => $title, 'metadata' => $metadata,
        ]);
    }

    /**
     * Snapshots for 30 Sep and 1 Oct 2026, taken shortly after midnight.
     *
     * @param  array{0: int, 1: int}  $values
     */
    private function snapshots(EventObject $pot, array $values): void
    {
        foreach (['2026-09-30' => $values[0], '2026-10-01' => $values[1]] as $date => $pence) {
            $day = EventObject::firstOrCreate(
                ['user_id' => $this->user->id, 'concept' => 'day', 'type' => 'day', 'title' => $date],
                ['time' => $date . ' 00:00:00', 'metadata' => []],
            );
            $event = Event::create([
                'integration_id' => $this->integration->id, 'source_id' => 'snap-' . (++$this->counter), 'actor_id' => $pot->id,
                'target_id' => $day->id, 'service' => 'monzo', 'domain' => 'money', 'action' => 'had_balance',
                'time' => $date . ' 23:59:59', 'value' => $pence, 'value_multiplier' => 100, 'value_unit' => 'GBP',
            ]);
            $event->forceFill(['created_at' => $date . ' 00:26:00'])->saveQuietly();
        }
    }
}
