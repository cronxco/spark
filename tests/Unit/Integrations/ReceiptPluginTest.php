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
        $this->assertEquals('o-document-text', $this->plugin->getIcon());
        $this->assertNotEmpty($this->plugin->getDescription());
    }

    /** @test */
    public function it_defines_receipt_action_types()
    {
        $actionTypes = $this->plugin->getActionTypes();

        $this->assertArrayHasKey('receipt_received_from', $actionTypes);

        $receiptAction = $actionTypes['receipt_received_from'];
        $this->assertEquals('Received receipt from', $receiptAction['display_singular']);
        $this->assertFalse($receiptAction['display_as_badge']);
        $this->assertTrue($receiptAction['display_as_relationship']);
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
    public function it_handles_sns_notification_webhook()
    {
        Queue::fake();

        $snsPayload = [
            'Type' => 'Notification',
            'MessageId' => 'test-message-id',
            'TopicArn' => 'arn:aws:sns:eu-west-1:123456789:spark-receipts',
            'Message' => json_encode([
                'notificationType' => 'Received',
                'mail' => [
                    'messageId' => 'email-message-id',
                    'timestamp' => now()->toIso8601String(),
                ],
                'receipt' => [
                    'action' => [
                        'type' => 'S3',
                        'topicArn' => 'arn:aws:sns:eu-west-1:123456789:spark-receipts',
                        'bucketName' => 'spark-receipts-emails',
                        'objectKey' => 'receipts/2025/01/email-message-id',
                    ],
                ],
            ]),
            'Timestamp' => now()->toIso8601String(),
            'SignatureVersion' => '1',
            'Signature' => 'test-signature',
        ];

        $request = new Request([], [], [], [], [], [], json_encode($snsPayload));
        $request->headers->set('x-amz-sns-message-type', 'Notification');

        $this->plugin->handleWebhook($request, $this->integration);

        Queue::assertPushed(ProcessReceiptEmailJob::class, function ($job) {
            return $job->s3ObjectKey === 'receipts/2025/01/email-message-id';
        });
    }

    /** @test */
    public function it_handles_sns_subscription_confirmation()
    {
        $snsPayload = [
            'Type' => 'SubscriptionConfirmation',
            'MessageId' => 'test-message-id',
            'Token' => 'test-token',
            'TopicArn' => 'arn:aws:sns:eu-west-1:123456789:spark-receipts',
            'Message' => 'You have chosen to subscribe...',
            'SubscribeURL' => 'https://sns.eu-west-1.amazonaws.com/?Action=ConfirmSubscription...',
            'Timestamp' => now()->toIso8601String(),
        ];

        $request = new Request([], [], [], [], [], [], json_encode($snsPayload));
        $request->headers->set('x-amz-sns-message-type', 'SubscriptionConfirmation');

        // Should not throw exception (confirmation handled automatically)
        $this->plugin->handleWebhook($request, $this->integration);

        $this->assertTrue(true); // No exception thrown
    }

    /** @test */
    public function it_extracts_s3_object_key_from_ses_notification()
    {
        $message = [
            'notificationType' => 'Received',
            'receipt' => [
                'action' => [
                    'type' => 'S3',
                    'objectKey' => 'receipts/test/key.eml',
                ],
            ],
        ];

        $reflection = new \ReflectionClass($this->plugin);
        $method = $reflection->getMethod('extractS3ObjectKey');
        $method->setAccessible(true);

        $key = $method->invoke($this->plugin, $message);

        $this->assertEquals('receipts/test/key.eml', $key);
    }

    /** @test */
    public function it_throws_exception_for_missing_s3_key()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('S3 object key not found');

        $message = [
            'notificationType' => 'Received',
            'receipt' => [
                'action' => [
                    'type' => 'S3',
                    // Missing objectKey
                ],
            ],
        ];

        $reflection = new \ReflectionClass($this->plugin);
        $method = $reflection->getMethod('extractS3ObjectKey');
        $method->setAccessible(true);

        $method->invoke($this->plugin, $message);
    }

    /** @test */
    public function it_parses_sns_message_correctly()
    {
        $innerMessage = [
            'notificationType' => 'Received',
            'mail' => ['messageId' => 'test-id'],
        ];

        $snsPayload = [
            'Type' => 'Notification',
            'Message' => json_encode($innerMessage),
        ];

        $request = new Request([], [], [], [], [], [], json_encode($snsPayload));

        $reflection = new \ReflectionClass($this->plugin);
        $method = $reflection->getMethod('parseSnsNotification');
        $method->setAccessible(true);

        $parsed = $method->invoke($this->plugin, $request);

        $this->assertEquals('Received', $parsed['notificationType']);
        $this->assertEquals('test-id', $parsed['mail']['messageId']);
    }

    /** @test */
    public function it_validates_sns_message_type_header()
    {
        $this->expectException(\Exception::class);

        $request = new Request([], [], [], [], [], [], '{}');
        // Missing x-amz-sns-message-type header

        $reflection = new \ReflectionClass($this->plugin);
        $method = $reflection->getMethod('parseSnsNotification');
        $method->setAccessible(true);

        $method->invoke($this->plugin, $request);
    }

    /** @test */
    public function it_supports_webhook_service_type()
    {
        $this->assertEquals('webhook', $this->plugin->getServiceType());
    }

    /** @test */
    public function it_requires_configuration()
    {
        $this->assertTrue($this->plugin->requiresConfiguration());
    }

    /** @test */
    public function it_returns_webhook_url()
    {
        $webhookUrl = $this->plugin->getWebhookUrl($this->integration);

        $this->assertStringContainsString('/webhook/receipt/', $webhookUrl);
        $this->assertStringContainsString((string) $this->integration->webhook_secret, $webhookUrl);
    }

    /** @test */
    public function it_defines_instance_types()
    {
        $instanceTypes = $this->plugin->getInstanceTypes();

        $this->assertArrayHasKey('receipt_inbox', $instanceTypes);

        $inbox = $instanceTypes['receipt_inbox'];
        $this->assertEquals('Receipt Inbox', $inbox['display_name']);
        $this->assertArrayHasKey('config_fields', $inbox);
    }
}
