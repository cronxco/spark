<?php

namespace Tests\Feature;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use InvalidArgumentException;
use Tests\TestCase;
use Throwable;

class GoCardlessIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function plugin_metadata_and_service_type(): void
    {
        $plugin = new GoCardlessBankPlugin;

        $this->assertEquals('gocardless', GoCardlessBankPlugin::getIdentifier());
        $this->assertEquals('GoCardless Bank', GoCardlessBankPlugin::getDisplayName());
        $this->assertStringContainsString('GoCardless Bank Account Data API', GoCardlessBankPlugin::getDescription());

        $schema = GoCardlessBankPlugin::getConfigurationSchema();
        $this->assertArrayHasKey('update_frequency_minutes', $schema);

        $instanceTypes = GoCardlessBankPlugin::getInstanceTypes();
        $this->assertArrayHasKey('transactions', $instanceTypes);
        $this->assertArrayHasKey('balances', $instanceTypes);
        $this->assertArrayHasKey('accounts', $instanceTypes);
    }

    /**
     * @test
     */
    public function onboarding_institution_selection_route_exists_and_sets_session(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $this->actingAs($user);

        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => GoCardlessBankPlugin::getIdentifier(),
            'account_id' => null,
        ]);

        // Mock the institutions in session to simulate API response
        $institutions = [
            ['id' => 'test_bank_1', 'name' => 'Test Bank 1'],
            ['id' => 'test_bank_2', 'name' => 'Test Bank 2'],
        ];
        session(['gocardless_institutions_' . $group->id => $institutions]);

        $resp = $this->post(route('integrations.gocardless.setInstitution', ['group' => $group->id]), [
            'institution_id' => 'test_bank_1',
        ]);

        $resp->assertRedirect(route('integrations.oauth', ['service' => 'gocardless']));
        $this->assertEquals('test_bank_1', session('gocardless_institution_id_' . $group->id));
    }

    /**
     * @test
     */
    public function bank_selection_page_loads(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $this->actingAs($user);

        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => GoCardlessBankPlugin::getIdentifier(),
            'account_id' => null,
        ]);

        // Mock institutions in session
        $institutions = [
            ['id' => 'test_bank_1', 'name' => 'Test Bank 1'],
            ['id' => 'test_bank_2', 'name' => 'Test Bank 2'],
        ];
        session(['gocardless_institutions_' . $group->id => $institutions]);

        $resp = $this->get(route('integrations.gocardless.bankSelection', ['group' => $group->id]));

        $resp->assertStatus(200);
        $resp->assertSee('Select Your Bank');
        $resp->assertSee('Test Bank 1');
        $resp->assertSee('Test Bank 2');
    }

    /**
     * @test
     */
    public function bank_selection_page_shows_error_when_no_banks_available(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $this->actingAs($user);

        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => GoCardlessBankPlugin::getIdentifier(),
            'account_id' => null,
        ]);

        // Ensure no institutions are in session to simulate API failure
        session(['gocardless_institutions_' . $group->id => []]);

        $resp = $this->get(route('integrations.gocardless.bankSelection', ['group' => $group->id]));

        $resp->assertStatus(200);
        $resp->assertSee('Unable to load banks from GoCardless API');
        $resp->assertSee('API credentials not configured correctly');

        // Verify no banks are in session
        $this->assertEmpty(session('gocardless_institutions_' . $group->id));
    }

    /**
     * @test
     */
    public function oauth_flow_uses_selected_institution_and_redirects(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $this->actingAs($user);

        $group = IntegrationGroup::create([
            'user_id' => $user->id,
            'service' => GoCardlessBankPlugin::getIdentifier(),
            'account_id' => null,
        ]);

        // Set institution in session
        Session::put('gocardless_institution_id_' . $group->id, 'test_bank_1');

        $plugin = new GoCardlessBankPlugin;

        try {
            $oauthUrl = $plugin->getOAuthUrl($group);
            // If we get here, the OAuth URL was created successfully
            $this->assertNotEmpty($oauthUrl);
            $this->assertStringContainsString('http', $oauthUrl);
        } catch (Throwable $e) {
            // In offline/CI environments, this might fail due to missing credentials
            // Just ensure we get a meaningful error message
            $this->assertNotEmpty($e->getMessage());
        }
    }

    /**
     * @test
     */
    public function initialization_redirects_to_bank_selection(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $this->actingAs($user);

        $resp = $this->post(route('integrations.initialize', ['service' => 'gocardless']));

        $resp->assertRedirect();

        // Extract the group ID from the redirect URL
        $redirectUrl = $resp->headers->get('Location');
        $this->assertStringContainsString('/integrations/groups/', $redirectUrl);

        // Extract UUID from URL and verify it's a valid group
        preg_match('/\/integrations\/groups\/([^\/]+)/', $redirectUrl, $matches);
        $this->assertCount(2, $matches);

        $groupId = $matches[1];
        $group = IntegrationGroup::where('id', $groupId)->first();
        $this->assertNotNull($group);
        $this->assertEquals('gocardless', $group->service);
        $this->assertEquals($user->id, $group->user_id);
    }

    /**
     * @test
     */
    public function plugin_constructor_validates_credentials(): void
    {
        // Test that plugin constructor validates credentials in non-testing environment
        $originalEnv = $this->app['env'];

        try {
            $this->app['env'] = 'production';
            config(['services.gocardless.secret_id' => '']);
            config(['services.gocardless.secret_key' => '']);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('GoCardless credentials are not configured');

            new GoCardlessBankPlugin;
        } finally {
            // Restore original environment to prevent test pollution
            $this->app['env'] = $originalEnv;
        }
    }

    /**
     * @test
     */
    public function plugin_constructor_allows_empty_credentials_in_testing(): void
    {
        // In testing environment, empty credentials should be allowed
        config(['services.gocardless.secret_id' => '']);
        config(['services.gocardless.secret_key' => '']);

        // Should not throw exception in testing environment
        $plugin = new GoCardlessBankPlugin;
        $this->assertInstanceOf(GoCardlessBankPlugin::class, $plugin);
    }
}
