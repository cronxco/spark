<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BriefingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function requires_authentication(): void
    {
        $this->getJson('/api/v1/mobile/briefing/today')->assertStatus(401);
    }

    #[Test]
    public function returns_summary_shape_for_today(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/briefing/today')
            ->assertOk()
            ->assertJsonStructure(['sections', 'anomalies', 'sync_status'])
            ->assertHeader('ETag')
            ->assertHeader('Last-Modified');
    }

    #[Test]
    public function today_is_resolved_in_the_users_timezone(): void
    {
        Carbon::setTestNow('2026-07-01 23:30:00 UTC');
        $user = User::factory()->create(['settings' => ['timezone' => 'Europe/London']]);
        Sanctum::actingAs($user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/briefing/today')
            ->assertOk()
            ->assertJsonPath('date', '2026-07-02')
            ->assertJsonPath('timezone', 'Europe/London');
    }

    #[Test]
    public function today_follows_an_acknowledged_travel_timezone(): void
    {
        // 21:00 in London on 25 Sep, but already 08:00 on 26 Sep in Auckland.
        Carbon::setTestNow('2026-09-25 20:00:00 UTC');
        $user = User::factory()->create(['settings' => ['timezone' => 'Europe/London']]);
        $checkin = Integration::factory()->create(['user_id' => $user->id, 'service' => 'daily_checkin']);
        Event::factory()->create([
            'integration_id' => $checkin->id,
            'service' => 'daily_checkin',
            'action' => 'time_travel',
            'event_metadata' => ['timezone' => 'Pacific/Auckland', 'acknowledged_at' => '2026-09-24T10:00:00.000000Z'],
        ]);
        Sanctum::actingAs($user, ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/briefing/today')
            ->assertOk()
            ->assertJsonPath('date', '2026-09-26')
            ->assertJsonPath('effective_timezone', 'Pacific/Auckland');

        $this->getJson('/api/v1/mobile/briefing/today?date=yesterday')
            ->assertOk()
            ->assertJsonPath('date', '2026-09-25');
    }

    #[Test]
    public function rejects_malformed_date(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/briefing/today?date=not-a-date')
            ->assertStatus(422);
    }

    #[Test]
    public function rejects_array_date_param(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/briefing/today?date[]=2024-01-01')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid date.');
    }

    #[Test]
    public function rejects_array_domains_param(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['ios:read', 'ios:write']);

        $this->getJson('/api/v1/mobile/briefing/today?domains[]=health')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid domains.');
    }

    #[Test]
    public function rejects_sloppy_iso_date_format(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['ios:read', 'ios:write']);

        // Carbon::parse would accept these; createFromFormat('Y-m-d') must not.
        $this->getJson('/api/v1/mobile/briefing/today?date=2024-1-1')->assertStatus(422);
        $this->getJson('/api/v1/mobile/briefing/today?date=2024-13-01')->assertStatus(422);
    }

    #[Test]
    public function etag_returns_304_on_match(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['ios:read', 'ios:write']);

        $first = $this->getJson('/api/v1/mobile/briefing/today')->assertOk();
        $etag = $first->headers->get('ETag');

        $this->assertNotNull($etag);

        $this->getJson('/api/v1/mobile/briefing/today', ['If-None-Match' => $etag])
            ->assertStatus(304);
    }

    #[Test]
    public function sync_status_reports_stale_and_as_of_for_a_never_synced_service(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['ios:read', 'ios:write']);

        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'oura']);
        Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'last_successful_update_at' => null,
        ]);

        $response = $this->getJson('/api/v1/mobile/briefing/today')->assertOk();

        $response->assertJsonPath('sync_status.oura.event_count', 0)
            ->assertJsonPath('sync_status.oura.stale', true)
            ->assertJsonPath('sync_status.oura.as_of', null);
    }

    #[Test]
    public function sync_status_is_fresh_when_the_integration_synced_within_its_cadence(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['ios:read', 'ios:write']);

        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'oura']);
        Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'oura',
            'last_successful_update_at' => now()->subMinutes(5),
        ]);

        $response = $this->getJson('/api/v1/mobile/briefing/today')->assertOk();

        $response->assertJsonPath('sync_status.oura.stale', false);
        $this->assertNotNull($response->json('sync_status.oura.as_of'));
    }

    #[Test]
    public function money_section_separates_spend_from_internal_transfers(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['ios:read', 'ios:write']);

        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'monzo']);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
        ]);

        $currentAccount = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'account',
            'type' => 'monzo_account',
            'title' => 'Current Account',
        ]);
        $pot = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'account',
            'type' => 'monzo_pot',
            'title' => 'Savings',
        ]);
        $merchant = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'merchant',
            'title' => 'Tesco',
        ]);

        // A quiet Sunday: one automated savings transfer and one small pot
        // withdrawal — no real spend at all.
        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'pot_transfer_to',
            'value' => 250827,
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
            'time' => Carbon::today()->setHour(9),
            'actor_id' => $currentAccount->id,
            'target_id' => $pot->id,
        ]);
        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'pot_withdrawal_to',
            'value' => 5200,
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
            'time' => Carbon::today()->setHour(10),
            'actor_id' => $currentAccount->id,
            'target_id' => $pot->id,
        ]);

        $response = $this->getJson('/api/v1/mobile/briefing/today')->assertOk();

        $response->assertJsonPath('sections.money.total_spend', 0)
            ->assertJsonPath('sections.money.internal_transfers', 2560.27);

        // A real purchase the same day does count as spend.
        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'monzo',
            'domain' => 'money',
            'action' => 'card_payment_to',
            'value' => 550,
            'value_multiplier' => 100,
            'value_unit' => 'GBP',
            'time' => Carbon::today()->setHour(11),
            'actor_id' => $currentAccount->id,
            'target_id' => $merchant->id,
        ]);

        $response = $this->getJson('/api/v1/mobile/briefing/today')->assertOk();
        $response->assertJsonPath('sections.money.total_spend', 5.5);
    }
}
