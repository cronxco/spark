<?php

namespace Tests\Feature;

use App\Integrations\Financial\FinancialPlugin;
use App\Integrations\PluginRegistry;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class FinancialIntegrationEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PluginRegistry::register(FinancialPlugin::class);
    }

    /**
     * @test
     */
    public function can_create_manual_account_object(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'manual_account',
        ]);

        $plugin = new FinancialPlugin;
        $accountData = [
            'name' => 'Test Savings Account',
            'account_type' => 'savings_account',
            'provider' => 'Test Bank',
            'currency' => 'GBP',
            'interest_rate' => 2.5,
        ];

        $accountObject = $plugin->upsertAccountObject($integration, $accountData);

        $this->assertInstanceOf(EventObject::class, $accountObject);
        $this->assertEquals('account', $accountObject->concept);
        $this->assertEquals('manual_account', $accountObject->type);
        $this->assertEquals('Test Savings Account', $accountObject->title);

        $metadata = $accountObject->metadata;
        $this->assertEquals('Test Savings Account', $metadata['name']);
        $this->assertEquals('savings_account', $metadata['account_type']);
        $this->assertEquals('Test Bank', $metadata['provider']);
        $this->assertEquals('GBP', $metadata['currency']);
        $this->assertEquals(2.5, $metadata['interest_rate']);
    }

    /**
     * @test
     */
    public function can_create_balance_event(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'manual_account',
        ]);

        $plugin = new FinancialPlugin;

        // Create account object first
        $accountData = [
            'name' => 'Test Account',
            'account_type' => 'current_account',
            'provider' => 'Test Bank',
            'currency' => 'GBP',
        ];
        $accountObject = $plugin->upsertAccountObject($integration, $accountData);

        // Create balance event
        $balanceData = [
            'balance' => 1000.50,
            'date' => '2025-01-27',
            'notes' => 'Monthly salary received',
        ];

        $balanceEvent = $plugin->createBalanceEvent($integration, $accountObject, $balanceData);

        $this->assertInstanceOf(Event::class, $balanceEvent);
        $this->assertEquals($integration->id, $balanceEvent->integration_id);
        $this->assertEquals('manual_account', $balanceEvent->service);
        $this->assertEquals('money', $balanceEvent->domain);
        $this->assertEquals('had_balance', $balanceEvent->action);
        $this->assertEquals(100050, $balanceEvent->value); // 1000.50 * 100
        $this->assertEquals(100, $balanceEvent->value_multiplier);
        $this->assertEquals('GBP', $balanceEvent->value_unit);
        $this->assertEquals($accountObject->id, $balanceEvent->actor_id);

        $eventMetadata = $balanceEvent->event_metadata;
        $this->assertEquals(1000.50, $eventMetadata['balance']);
        $this->assertEquals('Monthly salary received', $eventMetadata['notes']);
        $this->assertEquals('Test Account', $eventMetadata['account_name']);
        $this->assertEquals('current_account', $eventMetadata['account_type']);
        $this->assertEquals('Test Bank', $eventMetadata['provider']);
    }

    /**
     * @test
     */
    public function can_get_manual_accounts_for_user(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'manual_account',
        ]);

        $plugin = new FinancialPlugin;

        // Create multiple accounts
        $account1 = $plugin->upsertAccountObject($integration, [
            'name' => 'Account 1',
            'account_type' => 'current_account',
            'provider' => 'Bank A',
        ]);

        $account2 = $plugin->upsertAccountObject($integration, [
            'name' => 'Account 2',
            'account_type' => 'savings_account',
            'provider' => 'Bank B',
        ]);

        // Refresh the objects to ensure user_id is set
        $account1->refresh();
        $account2->refresh();

        $accounts = $plugin->getFinancialAccounts($user);

        $this->assertCount(2, $accounts);
        $this->assertTrue($accounts->contains($account1));
        $this->assertTrue($accounts->contains($account2));
    }

    /**
     * @test
     */
    public function can_get_balance_events_for_account(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'manual_account',
        ]);

        $plugin = new FinancialPlugin;

        $accountObject = $plugin->upsertAccountObject($integration, [
            'name' => 'Test Account',
            'account_type' => 'current_account',
            'provider' => 'Test Bank',
        ]);

        // Create multiple balance events
        $event1 = $plugin->createBalanceEvent($integration, $accountObject, [
            'balance' => 1000.00,
            'date' => '2025-01-25',
        ]);

        $event2 = $plugin->createBalanceEvent($integration, $accountObject, [
            'balance' => 1100.00,
            'date' => '2025-01-27',
        ]);

        $balanceEvents = $plugin->getBalanceEvents($accountObject);

        $this->assertCount(2, $balanceEvents);

        // Check that both events are in the collection by ID
        $eventIds = $balanceEvents->pluck('id')->toArray();
        $this->assertContains((string) $event1->id, $eventIds);
        $this->assertContains((string) $event2->id, $eventIds);

        // Verify the events are ordered by time descending (most recent first)
        $this->assertEquals((string) $event2->id, (string) $balanceEvents->first()->id);
        $this->assertEquals((string) $event1->id, (string) $balanceEvents->last()->id);
    }

    /**
     * @test
     */
    public function can_get_latest_balance_for_account(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'manual_account',
        ]);

        $plugin = new FinancialPlugin;

        $accountObject = $plugin->upsertAccountObject($integration, [
            'name' => 'Test Account',
            'account_type' => 'current_account',
            'provider' => 'Test Bank',
        ]);

        // Create balance events
        $plugin->createBalanceEvent($integration, $accountObject, [
            'balance' => 1000.00,
            'date' => '2025-01-25',
        ]);

        $latestEvent = $plugin->createBalanceEvent($integration, $accountObject, [
            'balance' => 1100.00,
            'date' => '2025-01-27',
        ]);

        $latestBalance = $plugin->getLatestBalance($accountObject);

        $this->assertInstanceOf(Event::class, $latestBalance);
        $this->assertEquals($latestEvent->id, $latestBalance->id);
        $this->assertEquals(1100.00, $latestBalance->event_metadata['balance']);
    }

    /**
     * @test
     */
    public function manual_plugin_does_not_support_oauth(): void
    {
        $user = User::factory()->create();
        $plugin = new FinancialPlugin;
        $group = $plugin->initializeGroup($user);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Manual integrations do not support OAuth');
        $plugin->handleOAuthCallback(request(), $group);
    }

    /**
     * @test
     */
    public function manual_plugin_does_not_support_webhooks(): void
    {
        $user = User::factory()->create();
        $plugin = new FinancialPlugin;
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'manual_account',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Manual integrations do not support webhooks');
        $plugin->handleWebhook(request(), $integration);
    }

    /**
     * @test
     */
    public function account_metadata_includes_integration_id(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'manual_account',
        ]);

        $plugin = new FinancialPlugin;
        $accountData = [
            'name' => 'Test Account',
            'account_type' => 'current_account',
            'provider' => 'Test Bank',
            'currency' => 'GBP',
        ];

        $accountObject = $plugin->upsertAccountObject($integration, $accountData);

        $this->assertArrayHasKey('integration_id', $accountObject->metadata);
        $this->assertEquals($integration->id, $accountObject->metadata['integration_id']);
    }

    /**
     * @test
     */
    public function can_get_manual_accounts_only_for_balance_updates(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'manual_account',
        ]);

        $plugin = new FinancialPlugin;

        // Create a manual account
        $manualAccount = $plugin->upsertAccountObject($integration, [
            'name' => 'Manual Account',
            'account_type' => 'current_account',
            'provider' => 'Test Bank',
        ]);

        // Debug: Check what was actually created
        $this->assertNotNull($manualAccount);
        $this->assertEquals($user->id, $manualAccount->user_id);
        $this->assertEquals('account', $manualAccount->concept);
        $this->assertEquals('manual_account', $manualAccount->type);

        // Refresh from database to ensure we have the latest data
        $manualAccount->refresh();

        // Create a mock Monzo account (different type)
        $monzoAccount = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'account',
            'type' => 'monzo_account',
            'title' => 'Monzo Account',
        ]);

        $manualAccounts = $plugin->getManualFinancialAccounts($user);

        // Debug: Check what accounts were created
        $this->assertCount(1, $manualAccounts);

        // Debug: Check the manual account details
        $this->assertEquals($user->id, $manualAccount->user_id);
        $this->assertEquals('account', $manualAccount->concept);
        $this->assertEquals('manual_account', $manualAccount->type);

        // Debug: Check what accounts were found
        $foundAccountIds = $manualAccounts->pluck('id')->toArray();
        $this->assertContains($manualAccount->id, $foundAccountIds, 'Manual account ID not found in results');

        $this->assertTrue($manualAccounts->contains($manualAccount));
        $this->assertFalse($manualAccounts->contains($monzoAccount));
    }

    /**
     * @test
     */
    public function can_get_all_financial_accounts_including_integrated_ones(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'manual_account',
        ]);

        $plugin = new FinancialPlugin;

        // Create a manual account
        $manualAccount = $plugin->upsertAccountObject($integration, [
            'name' => 'Manual Account',
            'account_type' => 'current_account',
            'provider' => 'Test Bank',
        ]);

        // Refresh from database to ensure we have the latest data
        $manualAccount->refresh();

        // Create a mock Monzo account
        $monzoAccount = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'account',
            'type' => 'monzo_account',
            'title' => 'Monzo Account',
        ]);

        // Create a mock GoCardless account
        $gocardlessAccount = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'account',
            'type' => 'bank_account',
            'title' => 'GoCardless Account',
        ]);

        $allAccounts = $plugin->getFinancialAccounts($user);

        $this->assertCount(3, $allAccounts);
        $this->assertTrue($allAccounts->contains($manualAccount));
        $this->assertTrue($allAccounts->contains($monzoAccount));
        $this->assertTrue($allAccounts->contains($gocardlessAccount));
    }

    /**
     * @test
     */
    public function balance_events_use_manual_account_service(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'manual_account',
        ]);

        $plugin = new FinancialPlugin;

        $accountObject = $plugin->upsertAccountObject($integration, [
            'name' => 'Test Account',
            'account_type' => 'current_account',
            'provider' => 'Test Bank',
        ]);

        $balanceEvent = $plugin->createBalanceEvent($integration, $accountObject, [
            'balance' => 1000.00,
            'date' => '2025-01-27',
        ]);

        $this->assertEquals('manual_account', $balanceEvent->service);
        $this->assertEquals('manual_balance_' . $accountObject->id . '_2025-01-27', $balanceEvent->source_id);
    }

    /**
     * @test
     */
    public function can_find_integration_by_metadata_or_name_fallback(): void
    {
        $user = User::factory()->create();
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'manual_account',
            'name' => 'Test Account Integration',
        ]);

        $plugin = new FinancialPlugin;

        // Test with integration_id in metadata
        $accountWithId = $plugin->upsertAccountObject($integration, [
            'name' => 'Account With ID',
            'account_type' => 'current_account',
            'provider' => 'Test Bank',
        ]);

        $this->assertArrayHasKey('integration_id', $accountWithId->metadata);
        $this->assertEquals($integration->id, $accountWithId->metadata['integration_id']);

        // Test fallback by name (simulate old account without integration_id)
        $oldAccount = EventObject::factory()->create([
            'user_id' => $user->id,
            'concept' => 'account',
            'type' => 'manual_account',
            'title' => 'Old Account',
            'metadata' => [
                'name' => 'Test Account Integration', // Same name as integration
                'account_type' => 'current_account',
                'provider' => 'Test Bank',
                // No integration_id
            ],
        ]);

        // Verify the old account doesn't have integration_id
        $this->assertArrayNotHasKey('integration_id', $oldAccount->metadata);
    }
}
