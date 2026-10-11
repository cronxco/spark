<?php

namespace Tests\Feature\Admin;

use App\Jobs\DeleteBinItemsBatch;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use App\Support\AdminTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class GodModeTest extends TestCase
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

        $this->assertFalse(AdminTenant::isGodMode());
        $this->assertSame($this->operator->id, AdminTenant::id());
    }

    #[Test]
    public function an_admin_who_is_not_a_global_operator_cannot_enable_it(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $this->expectException(InvalidArgumentException::class);
        AdminTenant::start($admin);
    }

    #[Test]
    public function a_non_operator_cannot_ask_for_a_fresh_sign_in(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.god-mode.enable'))->assertForbidden();
    }

    #[Test]
    public function an_operator_is_sent_to_authelia_for_a_fresh_sign_in(): void
    {
        config([
            'services.authelia.client_id' => 'spark',
            'services.authelia.client_secret' => 'secret',
            'services.authelia.redirect' => 'https://spark.test/auth/authelia/callback',
            'services.authelia.base_url' => 'https://auth.test',
        ]);

        $response = $this->actingAs($this->operator)->post(route('admin.god-mode.enable'));

        $response->assertRedirect();
        $this->assertStringContainsString('prompt=login', $response->headers->get('Location'));
        $this->assertStringContainsString('max_age=0', $response->headers->get('Location'));
        $this->assertFalse(AdminTenant::isGodMode());
    }

    #[Test]
    public function the_fresh_sign_in_request_is_only_honoured_once_for_the_same_user(): void
    {
        $this->actingAs($this->operator);

        AdminTenant::requestReauthentication($this->operator);
        $this->assertFalse(AdminTenant::pullReauthenticationRequest($this->customer));
        $this->assertFalse(AdminTenant::pullReauthenticationRequest($this->operator));

        AdminTenant::requestReauthentication($this->operator);
        $this->assertTrue(AdminTenant::pullReauthenticationRequest($this->operator));
        $this->assertFalse(AdminTenant::pullReauthenticationRequest($this->operator));
    }

    #[Test]
    public function an_expired_fresh_sign_in_request_is_refused(): void
    {
        $this->actingAs($this->operator);
        AdminTenant::requestReauthentication($this->operator);

        $this->travel(6)->minutes();

        $this->assertFalse(AdminTenant::pullReauthenticationRequest($this->operator));
    }

    #[Test]
    public function god_mode_shows_everyones_data_and_is_audited(): void
    {
        $customerEvent = $this->eventFor($this->customer);
        $ownEvent = $this->eventFor($this->operator);
        $this->actingAs($this->operator);

        Volt::test('admin.events')->assertSee($ownEvent->id)->assertDontSee($customerEvent->id);

        AdminTenant::start($this->operator);

        $this->assertTrue(AdminTenant::isGodMode());
        $this->assertNull(AdminTenant::id());
        Volt::test('admin.events')->assertSee($ownEvent->id)->assertSee($customerEvent->id);

        $this->get(route('admin.events.index'))->assertOk();

        $log = Activity::query()->where('log_name', 'security')->orderBy('id')->get();
        $this->assertSame(['god_mode_enabled', 'god_mode_viewed'], $log->pluck('description')->all());
        $this->assertTrue($log->every(fn (Activity $entry): bool => (string) $entry->causer_id === (string) $this->operator->id));
        $this->assertSame('admin.events.index', $log->last()->properties['page']);
    }

    #[Test]
    public function the_scope_helpers_skip_the_user_filter_only_in_god_mode(): void
    {
        $this->eventFor($this->customer);
        $this->eventFor($this->operator);
        $this->actingAs($this->operator);

        $this->assertSame(1, AdminTenant::scopeForUser(Event::query())->count());
        $this->assertSame(1, Integration::query()->tap(AdminTenant::scoped())->count());

        AdminTenant::start($this->operator);

        $this->assertSame(2, AdminTenant::scopeForUser(Event::query())->count());
        $this->assertSame(2, Integration::query()->tap(AdminTenant::scoped())->count());
    }

    #[Test]
    public function turning_it_off_returns_to_your_own_data_and_is_audited(): void
    {
        $this->actingAs($this->operator);
        AdminTenant::start($this->operator);

        $this->post(route('admin.god-mode.disable'))->assertRedirect(route('admin.god-mode.index'));

        $this->assertSame($this->operator->id, AdminTenant::id());
        $this->assertTrue(Activity::query()->where('log_name', 'security')->where('description', 'god_mode_disabled')->exists());
    }

    #[Test]
    public function it_expires(): void
    {
        $this->actingAs($this->operator);
        AdminTenant::start($this->operator);

        $this->travel(31)->minutes();

        $this->assertFalse(AdminTenant::isGodMode());
        $this->assertSame($this->operator->id, AdminTenant::id());
        $this->assertSame('expired', Activity::query()->where('description', 'god_mode_disabled')->sole()->properties['ended_because']);
    }

    #[Test]
    public function losing_operator_status_turns_it_off(): void
    {
        $this->actingAs($this->operator);
        AdminTenant::start($this->operator);

        config(['spark.admin.global_operators' => []]);

        $this->assertFalse(AdminTenant::isGodMode());
        $this->assertSame($this->operator->id, AdminTenant::id());
    }

    #[Test]
    public function bin_purge_covers_every_user_in_god_mode_and_is_audited(): void
    {
        Queue::fake();
        $this->actingAs($this->operator);

        $this->post(route('admin.bin.delete'))->assertOk();
        Queue::assertPushed(DeleteBinItemsBatch::class, 1);
        $this->assertFalse(Activity::query()->where('description', 'god_mode_action')->exists());

        AdminTenant::start($this->operator);
        $this->post(route('admin.bin.delete'))->assertOk();

        Queue::assertPushed(DeleteBinItemsBatch::class, 2);
        $this->assertSame('bin_purge', Activity::query()->where('description', 'god_mode_action')->sole()->properties['action']);
    }

    #[Test]
    public function the_god_mode_page_renders_for_operators_and_explains_itself_to_others(): void
    {
        $this->actingAs($this->operator)->get(route('admin.god-mode.index'))->assertOk()->assertSee('Turn on God Mode');

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.god-mode.index'))->assertOk()->assertSee('SPARK_GLOBAL_OPERATORS');
    }

    #[Test]
    public function admin_views_never_filter_on_the_raw_tenant_id(): void
    {
        $offenders = collect(glob(base_path('resources/views/livewire/admin/{,*/}*.blade.php'), GLOB_BRACE))
            ->filter(fn (string $file): bool => preg_match("/where\\('[a-z_.]+', AdminTenant::id\\(\\)\\)/", file_get_contents($file)) === 1);

        $this->assertSame([], $offenders->values()->all(), 'Use AdminTenant::scope()/scoped() so God Mode can lift the user filter.');
    }

    private function eventFor(User $user): Event
    {
        $integration = Integration::factory()->create(['user_id' => $user->id]);

        return Event::factory()->create(['integration_id' => $integration->id]);
    }
}
