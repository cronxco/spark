<?php

namespace Tests\Feature;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Jobs\GoCardless\CheckGoCardlessConsentExpiry;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Notifications\GoCardlessConsentExpiring;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoCardlessPendingSettlementTest extends TestCase
{
    private User $owner;

    private IntegrationGroup $group;

    private Integration $integration;

    private GoCardlessBankPlugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.enable_task_pipeline' => false]);
        Queue::fake();
        Cache::flush();
        Http::preventStrayRequests();
        $this->owner = User::factory()->create();
        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->owner->id, 'service' => 'gocardless', 'account_id' => 'requisition',
            'auth_metadata' => ['gocardless_agreement_id' => 'agreement-1', 'gocardless_institution_name' => 'Test Bank'],
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->owner->id, 'integration_group_id' => $this->group->id,
            'service' => 'gocardless', 'instance_type' => 'transactions',
            'configuration' => ['account_id' => 'account-1'],
        ]);
        $this->plugin = new class extends GoCardlessBankPlugin
        {
            public function getAccessToken(): string
            {
                return 'test-token';
            }
        };
    }

    #[Test]
    public function a_booked_transaction_settles_its_pending_twin_instead_of_duplicating(): void
    {
        $this->process($this->tx('2026-10-01', 'CARD PAYMENT TO COFFEE LTD', '-3.20'), 'pending');
        $this->process($this->tx('2026-10-03', 'Coffee Ltd', '-3.20'), 'booked');

        $events = Event::where('integration_id', $this->integration->id)->get();
        $this->assertCount(1, $events);
        $this->assertSame('booked', $events->first()->event_metadata['transaction_status']);

        // A stale pending copy arriving later must not move it back to pending
        $this->process($this->tx('2026-10-01', 'CARD PAYMENT TO COFFEE LTD', '-3.20'), 'pending');
        $this->assertSame('booked', $events->first()->fresh()->event_metadata['transaction_status']);
        $this->assertSame(1, Event::where('integration_id', $this->integration->id)->count());
    }

    #[Test]
    public function different_amounts_or_distant_dates_are_not_treated_as_the_same_transaction(): void
    {
        $this->process($this->tx('2026-10-01', 'Coffee Ltd', '-3.20'), 'pending');
        $this->process($this->tx('2026-10-02', 'Coffee Ltd', '-3.50'), 'booked');
        $this->process($this->tx('2026-10-20', 'Coffee Ltd', '-3.20'), 'booked');

        $this->assertSame(3, Event::where('integration_id', $this->integration->id)->count());
    }

    #[Test]
    public function reconcile_command_retires_historical_pending_duplicates_only_with_apply(): void
    {
        $this->process($this->tx('2026-10-01', 'CARD PAYMENT TO COFFEE LTD', '-3.20'), 'pending');
        $pending = Event::where('integration_id', $this->integration->id)->firstOrFail();
        // Simulate the old behaviour, where the booked copy got its own event
        $booked = $pending->replicate();
        $booked->source_id = 'gc_booked_copy';
        $booked->time = $pending->time->copy()->addDays(2);
        $booked->event_metadata = [...$pending->event_metadata, 'transaction_status' => 'booked'];
        $booked->save();

        $this->artisan('gocardless:reconcile-pending')->assertSuccessful();
        $this->assertNotSoftDeleted($pending);

        $this->artisan('gocardless:reconcile-pending --apply')->assertSuccessful();
        $this->assertSoftDeleted($pending);
        $this->assertSame((string) $booked->id, Event::withTrashed()->find($pending->id)->event_metadata['settled_as']);
        $this->assertNotSoftDeleted($booked);
    }

    #[Test]
    public function consent_expiry_warns_once_per_threshold(): void
    {
        Notification::fake();
        Http::fake(['*/agreements/enduser/agreement-1/' => Http::response([
            'accepted' => now()->subDays(85)->toIso8601String(), 'access_valid_for_days' => 90,
        ])]);
        $this->app->bind(GoCardlessBankPlugin::class, fn () => $this->plugin);

        (new CheckGoCardlessConsentExpiry)->handle();
        (new CheckGoCardlessConsentExpiry)->handle();

        Notification::assertSentToTimes($this->owner, GoCardlessConsentExpiring::class, 1);
        $metadata = $this->group->fresh()->auth_metadata;
        $this->assertSame('agreement-1', $metadata['eua_expires_for']);
        $this->assertSame([7], $metadata['eua_notifications_sent']);
        $this->assertSame('Test Bank', $metadata['gocardless_institution_name']);
        Http::assertSentCount(1);

        $this->travel(4)->days();
        (new CheckGoCardlessConsentExpiry)->handle();
        Notification::assertSentToTimes($this->owner, GoCardlessConsentExpiring::class, 2);
    }

    #[Test]
    public function consent_expiry_skips_groups_that_are_expired_or_fully_paused(): void
    {
        Notification::fake();
        $this->group->update(['auth_metadata' => [...$this->group->auth_metadata, 'eua_expired' => true]]);

        (new CheckGoCardlessConsentExpiry)->handle();

        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    #[Test]
    public function the_weekly_sweep_widens_the_normal_transaction_pull(): void
    {
        Http::fake(['*/accounts/account-1/transactions/*' => Http::response(['transactions' => ['booked' => [], 'pending' => []]])]);
        $plugin = new class extends GoCardlessBankPlugin
        {
            public function getAccessToken(): string
            {
                return 'test-token';
            }

            public function validateAccountExists(string $accountId, Integration $integration): bool
            {
                return true;
            }
        };

        $plugin->pullTransactionData($this->integration);
        $this->assertNotNull($this->integration->fresh()->configuration['gocardless_last_sweep_at'] ?? null);
        $plugin->pullTransactionData($this->integration->fresh());

        Http::assertSent(fn ($request) => $request['date_from'] === now()->subDays(60)->toDateString());
        Http::assertSent(fn ($request) => $request['date_from'] === now()->subDays(7)->toDateString());
    }

    private function process(array $tx, string $status): void
    {
        $this->plugin->processTransactionItem($this->integration, $this->account(), $tx, $status);
    }

    private function account(): array
    {
        return ['id' => 'account-1', 'details' => 'Current', 'resourceId' => 'resource-1', 'currency' => 'GBP', 'cashAccountType' => 'CACC'];
    }

    private function tx(string $date, string $name, string $amount): array
    {
        return [
            'bookingDate' => $date,
            'creditorName' => $name,
            'transactionAmount' => ['amount' => $amount, 'currency' => 'GBP'],
            'remittanceInformationUnstructured' => $name,
        ];
    }
}
