<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Integrations\Financial\FinancialPlugin;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\Api\ResourceVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MoneyAccountsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
    }

    #[Test]
    public function pins_a_manual_account_and_reports_it_on_index(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['ios:read', 'ios:write']);

        $account = $this->makeManualAccount($user, 'Current Account');

        $this->patchJson("/api/v1/mobile/money/accounts/{$account->id}", ['is_pinned' => true], [
            'If-Match' => app(ResourceVersion::class)->etag($account),
        ])->assertOk()->assertJsonPath('data.is_pinned', true);

        $this->getJson('/api/v1/mobile/money/accounts')
            ->assertOk()
            ->assertJsonPath('data.0.is_pinned', true);
    }

    #[Test]
    public function pinning_one_account_unpins_the_previous_one(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['ios:read', 'ios:write']);

        $first = $this->makeManualAccount($user, 'First');
        $second = $this->makeManualAccount($user, 'Second');

        $this->patchJson("/api/v1/mobile/money/accounts/{$first->id}", ['is_pinned' => true], [
            'If-Match' => app(ResourceVersion::class)->etag($first),
        ])->assertOk();

        $this->patchJson("/api/v1/mobile/money/accounts/{$second->id}", ['is_pinned' => true], [
            'If-Match' => app(ResourceVersion::class)->etag($second),
        ])->assertOk();

        $response = $this->getJson('/api/v1/mobile/money/accounts')->assertOk();
        $accounts = collect($response->json('data'))->keyBy('title');

        $this->assertFalse($accounts['First']['is_pinned']);
        $this->assertTrue($accounts['Second']['is_pinned']);
    }

    #[Test]
    public function index_paginates_with_the_shared_cursor_envelope(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['ios:read']);

        $this->makeManualAccount($user, 'First');
        $this->makeManualAccount($user, 'Second');
        $this->makeManualAccount($user, 'Third');

        $first = $this->getJson('/api/v1/mobile/money/accounts?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('has_more', true);

        $cursor = $first->json('next_cursor');
        $this->assertNotNull($cursor);

        $this->getJson('/api/v1/mobile/money/accounts?limit=2&cursor=' . urlencode($cursor))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('next_cursor', null);
    }

    #[Test]
    public function add_balance_with_idempotency_key_replays_instead_of_double_writing(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['ios:read', 'ios:write']);
        $account = $this->makeManualAccount($user, 'Current Account');

        $payload = ['balance' => 123.45, 'date' => now()->toDateString()];
        $headers = [
            'Idempotency-Key' => (string) Str::uuid(),
            'If-Match' => app(ResourceVersion::class)->etag($account),
        ];

        $first = $this->postJson("/api/v1/mobile/money/accounts/{$account->id}/balances", $payload, $headers)
            ->assertCreated();

        $second = $this->postJson("/api/v1/mobile/money/accounts/{$account->id}/balances", $payload, $headers)
            ->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));

        $balances = $this->getJson("/api/v1/mobile/money/accounts/{$account->id}/balances")->assertOk();
        $this->assertCount(1, $balances->json('data'));
    }

    #[Test]
    public function pinning_a_non_manual_account_is_allowed(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['ios:read', 'ios:write']);

        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'monzo']);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
        ]);

        $account = EventObject::create([
            'user_id' => $user->id,
            'concept' => 'account',
            'type' => 'monzo_account',
            'title' => 'Monzo Current',
            'time' => now(),
            'metadata' => ['currency' => 'GBP'],
        ]);

        $this->patchJson("/api/v1/mobile/money/accounts/{$account->id}", ['is_pinned' => true], [
            'If-Match' => app(ResourceVersion::class)->etag($account),
        ])->assertOk()->assertJsonPath('data.is_pinned', true);
    }

    #[Test]
    public function editing_account_data_on_a_non_manual_account_is_still_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['ios:read', 'ios:write']);

        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'monzo']);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
        ]);

        $account = EventObject::create([
            'user_id' => $user->id,
            'concept' => 'account',
            'type' => 'monzo_account',
            'title' => 'Monzo Current',
            'time' => now(),
            'metadata' => ['currency' => 'GBP'],
        ]);

        $this->patchJson("/api/v1/mobile/money/accounts/{$account->id}", ['name' => 'Renamed'], [
            'If-Match' => app(ResourceVersion::class)->etag($account),
        ])->assertStatus(422);
    }

    private function makeManualAccount(User $user, string $name): EventObject
    {
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'manual_account']);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'manual_account',
        ]);

        return (new FinancialPlugin)->upsertAccountObject($integration, [
            'name' => $name,
            'account_type' => 'current_account',
            'provider' => 'Test Bank',
            'currency' => 'GBP',
        ]);
    }
}
