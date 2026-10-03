<?php

namespace Tests\Feature\Admin;

use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use App\Support\AdminTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class OperatorAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create(['is_admin' => true]);
        $this->customer = User::factory()->create();
        config(['spark.admin.global_operators' => [(string) $this->operator->id]]);
    }

    #[Test]
    public function admin_pages_stay_on_your_own_data_by_default(): void
    {
        $this->actingAs($this->operator);

        $this->assertSame($this->operator->id, AdminTenant::id());
    }

    #[Test]
    public function an_admin_who_is_not_a_global_operator_cannot_start(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        session(['auth.password_confirmed_at' => time()]);

        $this->expectException(InvalidArgumentException::class);
        AdminTenant::start($admin, $this->customer, 'Investigating a sync bug');
    }

    #[Test]
    public function starting_needs_a_recent_password_confirmation_and_a_reason(): void
    {
        $this->actingAs($this->operator);

        try {
            AdminTenant::start($this->operator, $this->customer, 'Investigating a sync bug');
            $this->fail('Started without a password confirmation');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Confirm your password', $exception->getMessage());
        }

        session(['auth.password_confirmed_at' => time() - 16 * 60]);
        $this->expectException(InvalidArgumentException::class);
        AdminTenant::start($this->operator, $this->customer, 'Investigating a sync bug');
    }

    #[Test]
    public function a_short_reason_is_refused(): void
    {
        $this->actingAs($this->operator);
        session(['auth.password_confirmed_at' => time()]);

        $this->expectExceptionMessage('at least 10 characters');
        AdminTenant::start($this->operator, $this->customer, 'because');
    }

    #[Test]
    public function an_operator_context_scopes_admin_pages_to_the_customer_and_is_audited(): void
    {
        $customerEvent = $this->eventFor($this->customer);
        $ownEvent = $this->eventFor($this->operator);
        $this->actingAs($this->operator);
        session(['auth.password_confirmed_at' => time()]);

        AdminTenant::start($this->operator, $this->customer, 'Investigating a sync bug');

        $this->assertSame($this->customer->id, AdminTenant::id());
        Volt::test('admin.events')
            ->assertSee($customerEvent->id)
            ->assertDontSee($ownEvent->id);

        $this->get(route('admin.events.index'))->assertOk();

        $log = Activity::query()->where('log_name', 'security')->orderBy('id')->get();
        $this->assertSame(['operator_context_started', 'operator_context_viewed'], $log->pluck('description')->all());
        $this->assertTrue($log->every(fn (Activity $entry): bool => (string) $entry->causer_id === (string) $this->operator->id
            && (string) $entry->subject_id === (string) $this->customer->id
            && $entry->properties['reason'] === 'Investigating a sync bug'));
        $this->assertSame('admin.events.index', $log->last()->properties['page']);
    }

    #[Test]
    public function stopping_returns_to_your_own_data_and_is_audited(): void
    {
        $this->actingAs($this->operator);
        session(['auth.password_confirmed_at' => time()]);
        AdminTenant::start($this->operator, $this->customer, 'Investigating a sync bug');

        $this->post(route('admin.operator.stop'))->assertRedirect(route('admin.operator.index'));

        $this->assertSame($this->operator->id, AdminTenant::id());
        $this->assertTrue(Activity::query()->where('log_name', 'security')->where('description', 'operator_context_ended')->exists());
    }

    #[Test]
    public function the_context_expires(): void
    {
        $this->actingAs($this->operator);
        session(['auth.password_confirmed_at' => time()]);
        AdminTenant::start($this->operator, $this->customer, 'Investigating a sync bug');

        $this->travel(31)->minutes();

        $this->assertSame($this->operator->id, AdminTenant::id());
        $this->assertSame('expired', Activity::query()->where('description', 'operator_context_ended')->sole()->properties['ended_because']);
    }

    #[Test]
    public function losing_operator_status_ends_the_context(): void
    {
        $this->actingAs($this->operator);
        session(['auth.password_confirmed_at' => time()]);
        AdminTenant::start($this->operator, $this->customer, 'Investigating a sync bug');

        config(['spark.admin.global_operators' => []]);

        $this->assertSame($this->operator->id, AdminTenant::id());
    }

    #[Test]
    public function the_operator_page_asks_for_the_password_first(): void
    {
        $this->actingAs($this->operator)
            ->get(route('admin.operator.index'))
            ->assertRedirect(route('password.confirm'));
    }

    #[Test]
    public function the_operator_page_starts_a_context(): void
    {
        $this->actingAs($this->operator);
        session(['auth.password_confirmed_at' => time()]);

        Volt::test('admin.operator')
            ->set('email', $this->customer->email)
            ->set('reason', 'Investigating a sync bug')
            ->call('start')
            ->assertHasNoErrors();

        $this->assertSame($this->customer->id, AdminTenant::id());
    }

    private function eventFor(User $user): Event
    {
        $integration = Integration::factory()->create(['user_id' => $user->id]);

        return Event::factory()->create(['integration_id' => $integration->id]);
    }
}
