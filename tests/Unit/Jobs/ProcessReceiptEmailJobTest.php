<?php

namespace Tests\Unit\Jobs;

use App\Jobs\Data\Receipt\ProcessReceiptEmailJob;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class ProcessReceiptEmailJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private IntegrationGroup $group;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'receipt',
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'receipt',
            'instance_type' => 'receipts',
        ]);
    }

    #[Test]
    public function it_can_be_instantiated()
    {
        $job = new ProcessReceiptEmailJob(
            $this->integration,
            'receipts/test-email.eml'
        );

        $this->assertInstanceOf(ProcessReceiptEmailJob::class, $job);
    }

    #[Test]
    public function it_has_correct_timeout()
    {
        $job = new ProcessReceiptEmailJob(
            $this->integration,
            'receipts/test-email.eml'
        );

        $this->assertEquals(300, $job->timeout);
    }

    #[Test]
    public function it_has_correct_retry_settings()
    {
        $job = new ProcessReceiptEmailJob(
            $this->integration,
            'receipts/test-email.eml'
        );

        $this->assertEquals(3, $job->tries);
        $this->assertEquals([60, 300, 900], $job->backoff);
    }

    #[Test]
    public function it_generates_unique_id_based_on_integration_and_s3_key()
    {
        $s3Key = 'receipts/test-email-123.eml';
        $job = new ProcessReceiptEmailJob($this->integration, $s3Key);

        $uniqueId = $job->uniqueId();

        $this->assertStringStartsWith('process_receipt_email_', $uniqueId);
        $this->assertStringContainsString($this->integration->id, $uniqueId);
        $this->assertStringContainsString(md5($s3Key), $uniqueId);
    }

    #[Test]
    public function it_generates_different_unique_ids_for_different_s3_keys()
    {
        $job1 = new ProcessReceiptEmailJob(
            $this->integration,
            'receipts/email-1.eml'
        );

        $job2 = new ProcessReceiptEmailJob(
            $this->integration,
            'receipts/email-2.eml'
        );

        $this->assertNotEquals($job1->uniqueId(), $job2->uniqueId());
    }

    #[Test]
    public function it_generates_different_unique_ids_for_different_integrations()
    {
        $anotherIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'receipt',
            'instance_type' => 'receipts',
        ]);

        $s3Key = 'receipts/same-email.eml';

        $job1 = new ProcessReceiptEmailJob($this->integration, $s3Key);
        $job2 = new ProcessReceiptEmailJob($anotherIntegration, $s3Key);

        $this->assertNotEquals($job1->uniqueId(), $job2->uniqueId());
    }

    #[Test]
    public function it_can_be_dispatched_to_queue()
    {
        Queue::fake();

        ProcessReceiptEmailJob::dispatch(
            $this->integration,
            'receipts/test-email.eml'
        );

        Queue::assertPushed(ProcessReceiptEmailJob::class, function ($job) {
            return $job->integration->id === $this->integration->id
                && $job->s3ObjectKey === 'receipts/test-email.eml';
        });
    }

    #[Test]
    public function it_stores_s3_object_key()
    {
        $s3Key = 'receipts/folder/subfolder/email-12345.eml';
        $job = new ProcessReceiptEmailJob($this->integration, $s3Key);

        $this->assertEquals($s3Key, $job->s3ObjectKey);
    }

    #[Test]
    public function it_stores_integration_reference()
    {
        $job = new ProcessReceiptEmailJob(
            $this->integration,
            'receipts/test-email.eml'
        );

        $this->assertSame($this->integration->id, $job->integration->id);
        $this->assertEquals('receipt', $job->integration->service);
        $this->assertEquals('receipts', $job->integration->instance_type);
    }

    #[Test]
    public function it_handles_s3_keys_with_special_characters()
    {
        $s3Key = 'receipts/2024/01/email with spaces & special+chars.eml';
        $job = new ProcessReceiptEmailJob($this->integration, $s3Key);

        $this->assertEquals($s3Key, $job->s3ObjectKey);
        $this->assertStringContainsString(md5($s3Key), $job->uniqueId());
    }

    #[Test]
    public function it_implements_should_queue_interface()
    {
        $job = new ProcessReceiptEmailJob(
            $this->integration,
            'receipts/test-email.eml'
        );

        $this->assertInstanceOf(ShouldQueue::class, $job);
    }

    #[Test]
    public function it_uses_enhanced_idempotency_trait()
    {
        $job = new ProcessReceiptEmailJob(
            $this->integration,
            'receipts/test-email.eml'
        );

        // EnhancedIdempotency trait provides uniqueId method
        $this->assertTrue(method_exists($job, 'uniqueId'));
        $this->assertNotEmpty($job->uniqueId());
    }

    #[Test]
    public function it_captures_the_message_id_header_when_parsing_an_email()
    {
        $job = new ProcessReceiptEmailJob(
            $this->integration,
            'receipts/test-email.eml'
        );

        $rawEmail = <<<'EMAIL'
        From: receipts@digitalocean.com
        To: receipts@spark.cronx.co
        Subject: Your DigitalOcean Invoice
        Date: Sat, 01 Aug 2026 09:10:00 +0000
        Message-ID: <invoice-abc123@digitalocean.com>
        Content-Type: text/plain; charset=utf-8

        Thanks for your payment.
        EMAIL;

        $parseEmail = new ReflectionMethod($job, 'parseEmail');
        $result = $parseEmail->invoke($job, $rawEmail);

        $this->assertSame('invoice-abc123@digitalocean.com', $result['message_id']);
    }

    #[Test]
    public function it_returns_an_empty_message_id_when_the_header_is_missing()
    {
        $job = new ProcessReceiptEmailJob(
            $this->integration,
            'receipts/test-email.eml'
        );

        $rawEmail = <<<'EMAIL'
        From: receipts@digitalocean.com
        To: receipts@spark.cronx.co
        Subject: Your DigitalOcean Invoice
        Date: Sat, 01 Aug 2026 09:10:00 +0000
        Content-Type: text/plain; charset=utf-8

        Thanks for your payment.
        EMAIL;

        $parseEmail = new ReflectionMethod($job, 'parseEmail');
        $result = $parseEmail->invoke($job, $rawEmail);

        $this->assertSame('', $result['message_id']);
    }
}
