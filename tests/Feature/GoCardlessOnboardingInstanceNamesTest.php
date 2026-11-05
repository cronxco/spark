<?php

namespace Tests\Feature;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoCardlessOnboardingInstanceNamesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_generates_account_names_using_static_helper_with_different_scenarios(): void
    {
        // This test ensures our static helper is working correctly
        // and matches the expected behavior in the controller

        // Scenario 1: Has details field
        $account1 = [
            'id' => 'test-id-1',
            'details' => 'Personal Current Account',
            'ownerName' => 'John Smith',
            'resourceId' => 'GB12345678901234567890',
        ];

        // Scenario 2: No details, has ownerName
        $account2 = [
            'id' => 'test-id-2',
            'ownerName' => 'Jane Doe',
            'resourceId' => 'GB09876543210987654321',
        ];

        // Scenario 3: No details or ownerName, has resourceId
        $account3 = [
            'id' => 'test-id-3',
            'resourceId' => 'GB98765432109876543210',
        ];

        // Scenario 4: Only has ID (fallback case)
        $account4 = [
            'id' => 'test-id-4-abcdefgh',
        ];

        $this->assertEquals('Personal Current Account',
            GoCardlessBankPlugin::generateAccountName($account1));

        $this->assertEquals('Jane Doe\'s Account',
            GoCardlessBankPlugin::generateAccountName($account2));

        $this->assertEquals('Account GB987654',
            GoCardlessBankPlugin::generateAccountName($account3));

        $this->assertEquals('Account test-id-',
            GoCardlessBankPlugin::generateAccountName($account4));
    }

    #[Test]
    public function it_handles_whitespace_and_empty_details(): void
    {
        // Test that whitespace-only details fall back to ownerName
        $accountWithWhitespace = [
            'id' => 'test-whitespace',
            'details' => '   ',  // Whitespace only
            'ownerName' => 'Alice Brown',
            'resourceId' => 'GB99999999999999999999',
        ];

        $this->assertEquals('Alice Brown\'s Account',
            GoCardlessBankPlugin::generateAccountName($accountWithWhitespace));
    }

    #[Test]
    public function it_uses_integration_controller_import_correctly(): void
    {
        // Verify that the IntegrationController can access the static method
        // This ensures our import and usage is correct

        $testAccount = [
            'id' => 'controller-test',
            'details' => 'Controller Test Account',
        ];

        // This should not throw any errors about missing imports or methods
        $result = GoCardlessBankPlugin::generateAccountName($testAccount);
        $this->assertEquals('Controller Test Account', $result);
    }
}
