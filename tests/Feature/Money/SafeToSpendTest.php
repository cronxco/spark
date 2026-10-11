<?php

namespace Tests\Feature\Money;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\Money\SafeToSpend;
use App\Services\Money\UpcomingBills;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SafeToSpendTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.enable_task_pipeline' => false]);
        CarbonImmutable::setTestNow('2026-10-11 12:00:00');
        $this->user = User::factory()->create();
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => 'monzo'])->id,
            'service' => 'monzo', 'instance_type' => 'accounts',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function bills_due_before_payday_reduce_safe_to_spend(): void
    {
        $this->account('Current', 100000, '2026-10-11');
        $this->salary('2026-09-28');
        $this->debit('Council tax', 15000, '2026-08-20');
        $this->debit('Council tax', 15000, '2026-09-20');
        $this->debit('Gym', 3000, '2026-09-29');

        $plan = app(SafeToSpend::class)->build((string) $this->user->id, 0);

        $this->assertSame(15000, $plan['upcoming_bills']);
        $this->assertSame('2026-10-28', $plan['payday']);
        $this->assertSame(85000, $plan['amount']);
        $this->assertSame('Council tax', $plan['bills'][0]['payee']);
    }

    #[Test]
    public function one_off_debits_and_bills_after_payday_are_ignored(): void
    {
        $this->salary('2026-09-28');
        $this->debit('One off', 9900, '2026-09-15');
        $this->debit('Rent', 90000, '2026-08-30');
        $this->debit('Rent', 90000, '2026-09-30');

        $this->assertSame(0, app(UpcomingBills::class)->build((string) $this->user->id)['total']);
    }

    #[Test]
    public function several_current_accounts_are_summed(): void
    {
        $this->account('Current', 60000, '2026-10-11');
        $this->account('Joint', 40000, '2026-10-10');

        $plan = app(SafeToSpend::class)->build((string) $this->user->id, 0);

        $this->assertSame(100000, $plan['available']);
        $this->assertSame([], $plan['stale_accounts']);
    }

    #[Test]
    public function stale_or_missing_balances_are_excluded_and_named(): void
    {
        $this->account('Current', 60000, '2026-10-11');
        $this->account('Joint', 40000, '2026-10-01');
        $this->account('Never synced', null, null);

        $plan = app(SafeToSpend::class)->build((string) $this->user->id, 0);

        $this->assertSame(60000, $plan['available']);
        $this->assertEqualsCanonicalizing(['Joint', 'Never synced'], $plan['stale_accounts']);
    }

    #[Test]
    public function without_a_known_salary_payday_falls_back_to_month_end(): void
    {
        $bills = app(UpcomingBills::class)->build((string) $this->user->id);

        $this->assertFalse($bills['payday_known']);
        $this->assertSame('2026-10-31', $bills['payday']);
    }

    private function account(string $title, ?int $pence, ?string $day): EventObject
    {
        $account = EventObject::create(['user_id' => $this->user->id, 'concept' => 'account', 'type' => 'monzo_account', 'title' => $title, 'metadata' => []]);
        if ($pence !== null) {
            $this->event($account, 'had_balance', $pence, $day);
        }

        return $account;
    }

    private function salary(string $day): void
    {
        $employer = EventObject::create(['user_id' => $this->user->id, 'concept' => 'user', 'type' => 'payee', 'title' => 'Employer', 'metadata' => []]);
        $this->event($employer, 'salary_received_from', 300000, $day);
    }

    private function debit(string $payee, int $pence, string $day): void
    {
        $account = EventObject::firstOrCreate(['user_id' => $this->user->id, 'concept' => 'account', 'type' => 'monzo_account', 'title' => 'Debit source'], ['metadata' => []]);
        $target = EventObject::firstOrCreate(['user_id' => $this->user->id, 'concept' => 'user', 'type' => 'payee', 'title' => $payee], ['metadata' => []]);
        $this->event($account, 'direct_debit_to', $pence, $day, $target);
    }

    private function event(EventObject $actor, string $action, int $pence, string $day, ?EventObject $target = null): Event
    {
        $target ??= EventObject::firstOrCreate(
            ['user_id' => $this->user->id, 'concept' => 'day', 'type' => 'day', 'title' => $day],
            ['time' => $day.' 00:00:00', 'metadata' => []],
        );

        return Event::create([
            'integration_id' => $this->integration->id, 'source_id' => 'e-'.(++$this->counter), 'actor_id' => $actor->id,
            'target_id' => $target->id, 'service' => 'monzo', 'domain' => 'money', 'action' => $action,
            'time' => $day.' 09:00:00', 'value' => $pence, 'value_multiplier' => 100, 'value_unit' => 'GBP',
        ]);
    }
}
