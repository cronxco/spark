<?php

namespace Tests\Unit;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class GoCardlessAccountNameTest extends TestCase
{
    #[Test]
    public function it_generates_name_from_details_field(): void
    {
        $account = [
            'details' => 'Current Account',
            'ownerName' => 'John Smith',
            'resourceId' => 'GB123456789',
            'id' => 'account-123',
        ];

        $name = GoCardlessBankPlugin::generateAccountName($account);

        $this->assertEquals('Current Account', $name);
    }

    #[Test]
    public function it_generates_name_from_owner_name_when_details_empty(): void
    {
        $account = [
            'details' => '',
            'ownerName' => 'John Smith',
            'resourceId' => 'GB123456789',
            'id' => 'account-123',
        ];

        $name = GoCardlessBankPlugin::generateAccountName($account);

        $this->assertEquals("John Smith's Account", $name);
    }

    #[Test]
    public function it_generates_name_from_owner_name_when_details_missing(): void
    {
        $account = [
            'ownerName' => 'Jane Doe',
            'resourceId' => 'GB987654321',
            'id' => 'account-456',
        ];

        $name = GoCardlessBankPlugin::generateAccountName($account);

        $this->assertEquals("Jane Doe's Account", $name);
    }

    #[Test]
    public function it_generates_name_from_resource_id_when_no_details_or_owner(): void
    {
        $account = [
            'resourceId' => 'GB123456789012345',
            'id' => 'account-789',
        ];

        $name = GoCardlessBankPlugin::generateAccountName($account);

        $this->assertEquals('Account GB123456', $name);
    }

    #[Test]
    public function it_generates_name_from_id_when_no_resource_id(): void
    {
        $account = [
            'id' => 'account-abcdef123456',
        ];

        $name = GoCardlessBankPlugin::generateAccountName($account);

        $this->assertEquals('Account account-', $name);
    }

    #[Test]
    public function it_handles_empty_account_array(): void
    {
        $account = [];

        $name = GoCardlessBankPlugin::generateAccountName($account);

        $this->assertEquals('Account Unknown', $name);
    }

    #[Test]
    public function it_prefers_details_over_owner_name(): void
    {
        $account = [
            'details' => 'Savings Account',
            'ownerName' => 'John Smith',
        ];

        $name = GoCardlessBankPlugin::generateAccountName($account);

        $this->assertEquals('Savings Account', $name);
    }

    #[Test]
    public function it_ignores_whitespace_only_details(): void
    {
        $account = [
            'details' => '   ',
            'ownerName' => 'Alice Brown',
            'resourceId' => 'GB555666777',
        ];

        $name = GoCardlessBankPlugin::generateAccountName($account);

        $this->assertEquals("Alice Brown's Account", $name);
    }

    #[Test]
    public function it_can_instantiate_plugin_and_access_token_method(): void
    {
        // This test verifies that the getAccessToken method is public and accessible
        // In test environment, empty credentials are allowed
        config(['services.gocardless.secret_id' => '']);
        config(['services.gocardless.secret_key' => '']);

        $plugin = new GoCardlessBankPlugin;

        // We can't actually get a token in tests without credentials,
        // but we can verify the method is accessible
        $this->assertTrue(method_exists($plugin, 'getAccessToken'));

        // Verify it's a public method by checking if we can call it
        $reflection = new ReflectionMethod($plugin, 'getAccessToken');
        $this->assertTrue($reflection->isPublic());
    }
}
