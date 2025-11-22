<?php

namespace Tests\Unit\Integrations;

use App\Integrations\Receipt\ReceiptPlugin;
use App\Jobs\Data\Receipt\ProcessReceiptEmailJob;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReceiptPluginTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    private ReceiptPlugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'receipt',
        ]);
        $this->plugin = new ReceiptPlugin;
    }

    /** @test */
    public function it_has_correct_metadata()
    {
        $this->assertEquals('Receipt', $this->plugin->getDisplayName());
        $this->assertEquals('money', $this->plugin->getDomain());
        $this->assertEquals('fas.receipt', $this->plugin->getIcon());
        $this->assertNotEmpty($this->plugin->getDescription());
    }

    /** @test */
    public function it_defines_receipt_action_types()
    {
        $actionTypes = $this->plugin->getActionTypes();

        $this->assertArrayHasKey('receipt_received_from', $actionTypes);

        $receiptAction = $actionTypes['receipt_received_from'];
        $this->assertEquals('Receipt', $receiptAction['display_name']);
        $this->assertEquals('fas.receipt', $receiptAction['icon']);
        $this->assertTrue($receiptAction['display_with_object']);
    }

    /** @test */
    public function it_defines_block_types()
    {
        $blockTypes = $this->plugin->getBlockTypes();

        $this->assertArrayHasKey('receipt_line_item', $blockTypes);
        $this->assertArrayHasKey('receipt_tax_summary', $blockTypes);
        $this->assertArrayHasKey('receipt_payment_method', $blockTypes);

        $lineItemBlock = $blockTypes['receipt_line_item'];
        $this->assertEquals('Line Item', $lineItemBlock['display_name']);
        $this->assertEquals('fas.list', $lineItemBlock['icon']);
        $this->assertTrue($lineItemBlock['display_with_object']);
        $this->assertEquals('GBP', $lineItemBlock['value_unit']);
    }

    /** @test */
    public function it_defines_object_types()
    {
        $objectTypes = $this->plugin->getObjectTypes();

        $this->assertArrayHasKey('receipt_merchant', $objectTypes);

        $merchant = $objectTypes['receipt_merchant'];
        $this->assertEquals('Receipt Merchant', $merchant['display_name']);
        $this->assertEquals('fas.store', $merchant['icon']);
    }

    /** @test */
    public function it_defines_instance_types()
    {
        $instanceTypes = $this->plugin->getInstanceTypes();

        $this->assertArrayHasKey('receipts', $instanceTypes);

        $receipts = $instanceTypes['receipts'];
        $this->assertEquals('Receipts', $receipts['label']);
    }

    /** @test */
    public function it_supports_webhook_service_type()
    {
        $this->assertEquals('webhook', $this->plugin->getServiceType());
    }

    /** @test */
    public function it_returns_correct_identifier()
    {
        $this->assertEquals('receipt', $this->plugin->getIdentifier());
    }

    /** @test */
    public function it_returns_success_accent_color()
    {
        $this->assertEquals('success', $this->plugin->getAccentColor());
    }
}
