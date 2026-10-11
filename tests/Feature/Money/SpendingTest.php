<?php

namespace Tests\Feature\Money;

use App\Livewire\Money\Budgets as BudgetsComponent;
use App\Livewire\Money\Spending as SpendingComponent;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Jev\JevAssessment;
use App\Services\Jev\JevClient;
use App\Services\Money\BudgetService;
use App\Services\Money\SpendingAssessor;
use App\Services\Money\SpendingScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpendingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $monzo;

    private Integration $bank;

    private EventObject $current;

    private EventObject $amex;

    private EventObject $barclays;

    private int $counter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.enable_task_pipeline' => false]);
        Livewire::withoutLazyLoading();
        $this->user = User::factory()->create();
        $this->monzo = $this->integration('monzo');
        $this->bank = $this->integration('gocardless');
        $this->current = $this->account('monzo_account', 'Monzo', []);
        $this->amex = $this->account('bank_account', 'Amex', ['account_type' => 'credit_card']);
        $this->barclays = $this->account('bank_account', 'Barclays', ['account_type' => 'current']);
    }

    #[Test]
    public function it_counts_card_spending_and_refunds_but_not_money_movements(): void
    {
        $this->monzoEvent('card_payment_to', 1250, 'Pret', 'eating_out');
        $this->monzoEvent('card_refund_from', 300, 'Pret', 'eating_out');
        foreach (['pot_transfer_to', 'direct_debit_to', 'bank_transfer_to', 'salary_received_from', 'declined_payment_to', 'monzo_flex_payment', 'interest_earned'] as $action) {
            $this->monzoEvent($action, 5000, 'Other', 'general');
        }
        $this->monzoEvent('card_payment_to', 900, 'Hidden', 'general', ['include_in_spending' => false]);

        $entries = app(SpendingScope::class)->entries($this->user->id, $this->start(), $this->end());

        $this->assertSame([-300, 1250], $entries->pluck('amount')->sort()->values()->all());
        $this->assertSame('Pret', $entries->first()['merchant']);
        $this->assertSame('eating_out', $entries->first()['category']);
    }

    #[Test]
    public function it_counts_credit_card_lines_from_open_banking_but_not_repayments_or_current_accounts(): void
    {
        $this->bankEvent($this->amex, 'payment_to', 4000, 'Hotel', 'Hotel stay');
        $this->bankEvent($this->amex, 'payment_from', 1000, 'Hotel', 'Refund of deposit');
        $this->bankEvent($this->amex, 'payment_from', 90_000, 'Payment', 'DIRECT DEBIT PAYMENT - THANK YOU');
        $linked = $this->bankEvent($this->amex, 'payment_from', 20_000, 'Payment', 'Payment');
        $this->bankEvent($this->amex, 'payment_to', 700, 'Shop', 'OUTSTANDING AUTHORISATION');
        $this->bankEvent($this->barclays, 'payment_to', 60_000, 'Landlord', 'Rent');

        $other = $this->bankEvent($this->barclays, 'payment_to', 20_000, 'Amex', 'Card');
        Relationship::createRelationship([
            'user_id' => $this->user->id, 'from_type' => Event::class, 'from_id' => $other->id,
            'to_type' => Event::class, 'to_id' => $linked->id, 'type' => 'payment_for',
        ]);

        $entries = app(SpendingScope::class)->entries($this->user->id, $this->start(), $this->end());

        $this->assertSame([-1000, 4000], $entries->pluck('amount')->sort()->values()->all());
    }

    #[Test]
    public function it_filters_by_category_account_and_person(): void
    {
        $this->monzoEvent('card_payment_to', 1000, 'Tesco', 'groceries')->attachTag('Will', 'person');
        $this->monzoEvent('card_payment_to', 2000, 'Pret', 'eating_out')->attachTag('Dan', 'person');

        $scope = app(SpendingScope::class);

        $this->assertSame([1000], $scope->entries($this->user->id, $this->start(), $this->end(), ['category' => 'groceries'])->pluck('amount')->all());
        $this->assertSame([2000], $scope->entries($this->user->id, $this->start(), $this->end(), ['person' => 'dan'])->pluck('amount')->all());
        $this->assertCount(2, $scope->entries($this->user->id, $this->start(), $this->end(), ['account' => (string) $this->current->id]));
        $this->assertCount(0, $scope->entries($this->user->id, $this->start(), $this->end(), ['account' => (string) $this->amex->id]));
    }

    #[Test]
    public function it_never_shows_another_users_spending(): void
    {
        $this->monzoEvent('card_payment_to', 1000, 'Tesco', 'groceries');
        $stranger = User::factory()->create();

        $this->assertCount(0, app(SpendingScope::class)->entries($stranger->id, $this->start(), $this->end()));
    }

    #[Test]
    public function budgets_track_calendar_month_spend_by_category(): void
    {
        $service = app(BudgetService::class);
        $service->create($this->user->id, 'Eating out', ['amount' => 25_000, 'categories' => ['Eating Out']]);
        $service->create($this->user->id, 'Groceries', ['amount' => 40_000, 'categories' => ['groceries']]);
        $this->monzoEvent('card_payment_to', 20_000, 'Restaurant', 'eating_out');
        $this->monzoEvent('card_payment_to', 6_000, 'Cafe', 'eating_out');
        $this->monzoEvent('card_payment_to', 10_000, 'Tesco', 'groceries');
        $this->monzoEvent('card_refund_from', 1_000, 'Tesco', 'groceries');
        $this->monzoEvent('card_payment_to', 99_000, 'Old', 'eating_out', [], '2026-09-30 12:00:00');

        $progress = collect($service->progress($this->user->id, CarbonImmutable::parse('2026-10-20')))->keyBy('name');

        $this->assertSame(26_000, $progress['Eating out']['spent']);
        $this->assertSame(-1_000, $progress['Eating out']['remaining']);
        $this->assertSame('over', $progress['Eating out']['status']);
        $this->assertSame(9_000, $progress['Groceries']['spent']);
        $this->assertSame('ok', $progress['Groceries']['status']);
        $this->assertSame('Eating out', $service->progress($this->user->id, CarbonImmutable::parse('2026-10-20'))[0]['name']);
    }

    #[Test]
    public function an_explicit_assignment_overrides_the_category_and_can_be_cleared(): void
    {
        $service = app(BudgetService::class);
        $eating = $service->create($this->user->id, 'Eating out', ['amount' => 25_000, 'categories' => ['eating_out']]);
        $treats = $service->create($this->user->id, 'Treats', ['amount' => 10_000, 'categories' => ['entertainment']]);
        $event = $this->monzoEvent('card_payment_to', 3_000, 'Restaurant', 'eating_out');
        $now = CarbonImmutable::parse('2026-10-20');

        $service->assign($this->user->id, (string) $event->id, (string) $treats->id);
        $spent = collect($service->progress($this->user->id, $now))->pluck('spent', 'name');
        $this->assertSame(3_000, $spent['Treats']);
        $this->assertSame(0, $spent['Eating out']);

        $service->assign($this->user->id, (string) $event->id, null);
        $spent = collect($service->progress($this->user->id, $now))->pluck('spent', 'name');
        $this->assertSame(3_000, $spent['Eating out']);
        $this->assertSame(0, Relationship::where('type', 'part_of')->count());
    }

    #[Test]
    public function budgets_respect_merchant_and_person_rules_and_archiving(): void
    {
        $service = app(BudgetService::class);
        $budget = $service->create($this->user->id, 'Coffee', [
            'amount' => 5_000, 'merchants_include' => ['Pret'], 'merchants_exclude' => ['Pret Airport'], 'people' => ['Will'],
        ]);
        $this->monzoEvent('card_payment_to', 400, 'Pret', 'eating_out')->attachTag('Will', 'person');
        $this->monzoEvent('card_payment_to', 500, 'Pret', 'eating_out')->attachTag('Dan', 'person');
        $this->monzoEvent('card_payment_to', 600, 'Pret Airport', 'eating_out')->attachTag('Will', 'person');
        $now = CarbonImmutable::parse('2026-10-20');

        $this->assertSame(400, $service->progress($this->user->id, $now)[0]['spent']);

        $service->archive($this->user->id, (string) $budget->id);
        $this->assertSame([], $service->progress($this->user->id, $now));
        $this->assertSame('Coffee', $service->all($this->user->id, true)->first()->title);
    }

    #[Test]
    public function it_rejects_bad_budgets(): void
    {
        $service = app(BudgetService::class);
        $service->create($this->user->id, 'Fun', ['amount' => 1000]);

        foreach ([['', 1000], ['Zero', 0], ['Fun', 1000]] as [$name, $amount]) {
            try {
                $service->create($this->user->id, $name, ['amount' => $amount]);
                $this->fail("Expected {$name}/{$amount} to be rejected");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function the_components_create_a_budget_and_list_spending(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $this->monzoEvent('card_payment_to', 1234, 'Pret', 'eating_out');

        Livewire::actingAs($this->user)->test(BudgetsComponent::class)
            ->set('name', 'Eating out')->set('amount', '£250')->set('categories', 'eating_out, groceries')
            ->call('create')->assertHasNoErrors()
            ->assertSee('Eating out')->assertSee('£12.34 of £250')
            ->set('name', 'Eating out')->set('amount', '10')->call('create')->assertHasErrors('name');

        $this->assertSame(25_000, EventObject::where('type', 'spending_budget')->first()->metadata['budget']['amount']);

        Livewire::withoutLazyLoading();
        Livewire::actingAs($this->user)->test(SpendingComponent::class)
            ->assertSee('Pret')->assertSee('£12.34')->assertSee('October 2026')
            ->set('category', 'groceries')->assertDontSee('Pret');
    }

    #[Test]
    public function the_assessor_records_shadow_answers_on_ambiguous_bank_lines_only(): void
    {
        config(['money.jev.enabled' => true]);
        $rent = $this->bankEvent($this->barclays, 'payment_to', 60_000, 'Landlord', 'Rent');
        $this->bankEvent($this->amex, 'payment_to', 1000, 'Shop', 'Shop');

        $jev = Mockery::mock(JevClient::class);
        $jev->shouldReceive('isConfigured')->andReturn(true);
        $jev->shouldReceive('ask')->once()->andReturn(new JevAssessment('jev-test', [
            "tx_{$rent->id}_class" => ['type' => 'choice', 'choice' => 'bill', 'probabilities' => ['bill' => 0.9, 'spending' => 0.1]],
            "tx_{$rent->id}_category" => ['type' => 'choice', 'choice' => 'bills', 'probabilities' => ['bills' => 0.4]],
            "tx_{$rent->id}_recurring" => ['type' => 'noul', 'noul' => 0.95],
        ]));
        $this->app->instance(JevClient::class, $jev);

        $assessor = app(SpendingAssessor::class);
        $candidates = $assessor->candidates($this->user->id, 30, 50);
        $this->assertSame([(string) $rent->id], $candidates->pluck('id')->map(fn ($id) => (string) $id)->all());

        $this->assertSame(1, $assessor->assess($candidates));

        $assessment = $rent->refresh()->event_metadata['money_assessment'];
        $this->assertSame('shadow', $assessment['mode']);
        $this->assertSame('bill', $assessment['spending_class']);
        $this->assertTrue($assessment['spending_class_confident']);
        $this->assertFalse($assessment['category_confident']);
        $this->assertTrue($assessment['recurring_confident']);
        $this->assertCount(0, $assessor->candidates($this->user->id, 30, 50));
        $this->assertCount(0, app(SpendingScope::class)->entries($this->user->id, $this->start(), $this->end()->addYear())->where('merchant', 'Landlord'));
    }

    #[Test]
    public function the_assessor_does_nothing_while_disabled(): void
    {
        config(['money.jev.enabled' => false]);
        $this->bankEvent($this->barclays, 'payment_to', 60_000, 'Landlord', 'Rent');

        $this->assertSame(0, app(SpendingAssessor::class)->assess(app(SpendingAssessor::class)->candidates($this->user->id, 30, 50)));
        $this->artisan('money:assess-spending')->expectsOutputToContain('disabled')->assertExitCode(0);
    }

    private function start(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-01 00:00:00');
    }

    private function end(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-31 23:59:59');
    }

    private function integration(string $service): Integration
    {
        return Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => $service])->id,
            'service' => $service,
            'instance_type' => 'accounts',
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function account(string $type, string $title, array $metadata): EventObject
    {
        return EventObject::create(['user_id' => $this->user->id, 'concept' => 'account', 'type' => $type, 'title' => $title, 'metadata' => $metadata]);
    }

    private function merchant(string $title): EventObject
    {
        return EventObject::firstOrCreate(
            ['user_id' => $this->user->id, 'concept' => 'counterparty', 'type' => 'monzo_counterparty', 'title' => $title],
            ['time' => now(), 'metadata' => []],
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function monzoEvent(string $action, int $pence, string $merchant, string $category, array $extra = [], string $time = '2026-10-10 12:00:00'): Event
    {
        $event = Event::create([
            'integration_id' => $this->monzo->id, 'source_id' => 'm-'.(++$this->counter), 'actor_id' => $this->current->id,
            'target_id' => $this->merchant($merchant)->id, 'service' => 'monzo', 'domain' => 'money', 'action' => $action,
            'time' => $time, 'value' => $pence, 'value_multiplier' => 100, 'value_unit' => 'GBP',
            'event_metadata' => ['category' => $category] + $extra,
        ]);
        $event->attachTag($category, 'transaction_category');

        return $event;
    }

    private function bankEvent(EventObject $account, string $action, int $pence, string $merchant, string $description): Event
    {
        return Event::create([
            'integration_id' => $this->bank->id, 'source_id' => 'g-'.(++$this->counter), 'actor_id' => $account->id,
            'target_id' => $this->merchant($merchant)->id, 'service' => 'gocardless', 'domain' => 'money', 'action' => $action,
            'time' => '2026-10-10 12:00:00', 'value' => $pence, 'value_multiplier' => 100, 'value_unit' => 'GBP',
            'event_metadata' => ['description' => $description, 'transaction_status' => 'booked'],
        ]);
    }
}
