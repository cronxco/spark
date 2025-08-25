<?php

namespace Tests\Feature;

use App\Integrations\Manual\ManualFinancialPlugin;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualFinancialIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private ManualFinancialPlugin $plugin;
    private IntegrationGroup $group;
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->plugin = new ManualFinancialPlugin();
        $this->group = $this->plugin->initializeGroup($this->user);
        $this->integration = $this->plugin->createInstance($this->group, 'accounts');
    }

    public function test_plugin_identifier(): void
    {
        $this->assertEquals('manual-financial', ManualFinancialPlugin::getIdentifier());
    }

    public function test_plugin_display_name(): void
    {
        $this->assertEquals('Manual Financial', ManualFinancialPlugin::getDisplayName());
    }

    public function test_plugin_description(): void
    {
        $this->assertStringContainsString('Manually track your financial accounts', ManualFinancialPlugin::getDescription());
    }

    public function test_plugin_service_type(): void
    {
        $this->assertEquals('manual', ManualFinancialPlugin::getServiceType());
    }

    public function test_configuration_schema(): void
    {
        $schema = ManualFinancialPlugin::getConfigurationSchema();
        
        $this->assertArrayHasKey('account_type', $schema);
        $this->assertArrayHasKey('provider_name', $schema);
        $this->assertArrayHasKey('currency', $schema);
        $this->assertArrayHasKey('interest_rate', $schema);
        
        $this->assertEquals('select', $schema['account_type']['type']);
        $this->assertEquals('text', $schema['provider_name']['type']);
        $this->assertEquals('select', $schema['currency']['type']);
        $this->assertEquals('number', $schema['interest_rate']['type']);
    }

    public function test_instance_types(): void
    {
        $types = ManualFinancialPlugin::getInstanceTypes();
        
        $this->assertArrayHasKey('accounts', $types);
        $this->assertArrayHasKey('balances', $types);
        
        $this->assertEquals('Financial Accounts', $types['accounts']['label']);
        $this->assertEquals('Balance Updates', $types['balances']['label']);
    }

    public function test_initialize_group(): void
    {
        $group = $this->plugin->initializeGroup($this->user);
        
        $this->assertInstanceOf(IntegrationGroup::class, $group);
        $this->assertEquals($this->user->id, $group->user_id);
        $this->assertEquals('manual-financial', $group->service);
        $this->assertNull($group->account_id);
    }

    public function test_create_instance(): void
    {
        $instance = $this->plugin->createInstance($this->group, 'accounts');
        
        $this->assertInstanceOf(Integration::class, $instance);
        $this->assertEquals($this->user->id, $instance->user_id);
        $this->assertEquals($this->group->id, $instance->integration_group_id);
        $this->assertEquals('manual-financial', $instance->service);
        $this->assertEquals('accounts', $instance->instance_type);
    }

    public function test_create_account(): void
    {
        $accountData = [
            'account_type' => 'savings',
            'provider_name' => 'Barclays',
            'account_number' => '12345678',
            'currency' => 'GBP',
            'interest_rate' => 2.5,
        ];

        $account = $this->plugin->createAccount($this->integration, $accountData);
        
        $this->assertInstanceOf(EventObject::class, $account);
        $this->assertEquals($this->integration->id, $account->integration_id);
        $this->assertEquals($this->user->id, $account->user_id);
        $this->assertEquals('financial_account', $account->concept);
        $this->assertEquals('account', $account->type);
        $this->assertEquals('Barclays - Savings', $account->title);
        
        $this->assertEquals('savings', $account->metadata['account_type']);
        $this->assertEquals('Barclays', $account->metadata['provider_name']);
        $this->assertEquals('12345678', $account->metadata['account_number']);
        $this->assertEquals('GBP', $account->metadata['currency']);
        $this->assertEquals(2.5, $account->metadata['interest_rate']);
    }

    public function test_create_balance_update(): void
    {
        // First create an account
        $accountData = [
            'account_type' => 'savings',
            'provider_name' => 'Barclays',
            'currency' => 'GBP',
        ];
        
        $account = $this->plugin->createAccount($this->integration, $accountData);
        
        // Then create a balance update
        $balanceData = [
            'account_id' => $account->id,
            'balance' => 5000.00,
            'date' => '2024-01-15',
            'notes' => 'Monthly statement balance',
        ];
        
        $balance = $this->plugin->createBalanceUpdate($this->integration, $balanceData);
        
        $this->assertInstanceOf(Event::class, $balance);
        $this->assertEquals($this->integration->id, $balance->integration_id);
        $this->assertEquals($account->id, $balance->actor_id);
        $this->assertEquals('manual-financial', $balance->service);
        $this->assertEquals('finance', $balance->domain);
        $this->assertEquals('balance_update', $balance->action);
        $this->assertEquals(5000.00, $balance->value);
        $this->assertEquals('currency', $balance->value_unit);
        
        $this->assertEquals($account->id, $balance->event_metadata['account_id']);
        $this->assertEquals('Monthly statement balance', $balance->event_metadata['notes']);
        $this->assertEquals('GBP', $balance->event_metadata['currency']);
    }

    public function test_create_balance_update_with_invalid_account(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Account not found');
        
        $balanceData = [
            'account_id' => 'invalid-uuid',
            'balance' => 5000.00,
            'date' => '2024-01-15',
        ];
        
        $this->plugin->createBalanceUpdate($this->integration, $balanceData);
    }

    public function test_get_accounts_for_user(): void
    {
        // Create multiple accounts
        $accountData1 = [
            'account_type' => 'savings',
            'provider_name' => 'Barclays',
            'currency' => 'GBP',
        ];
        
        $accountData2 = [
            'account_type' => 'mortgage',
            'provider_name' => 'Santander',
            'currency' => 'GBP',
        ];
        
        $this->plugin->createAccount($this->integration, $accountData1);
        $this->plugin->createAccount($this->integration, $accountData2);
        
        $accounts = $this->plugin->getAccountsForUser($this->user);
        
        $this->assertCount(2, $accounts);
        $this->assertEquals('Barclays - Savings', $accounts[0]['title']);
        $this->assertEquals('Santander - Mortgage', $accounts[1]['title']);
        $this->assertEquals('savings', $accounts[0]['account_type']);
        $this->assertEquals('mortgage', $accounts[1]['account_type']);
    }

    public function test_fetch_data_does_nothing(): void
    {
        // This should not throw any errors
        $this->plugin->fetchData($this->integration);
        
        // No events should be created
        $this->assertEquals(0, Event::count());
    }

    public function test_convert_data_returns_empty_array(): void
    {
        $result = $this->plugin->convertData(['some' => 'data'], $this->integration);
        
        $this->assertEquals([], $result);
    }
}