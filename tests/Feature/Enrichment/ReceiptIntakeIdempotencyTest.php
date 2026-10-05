<?php

namespace Tests\Feature\Enrichment;

use App\Integrations\Receipt\ReceiptExtractor;
use App\Jobs\Data\Receipt\MatchReceiptToTransactionJob;
use App\Jobs\Data\Receipt\ProcessReceiptEmailJob;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ENR-02 / ENR-03: a redelivered receipt email must not create a second
 * receipt or a second AI extraction, and matching runs once, from the Task
 * Pipeline, rather than also from a directly dispatched legacy job.
 */
class ReceiptIntakeIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->integration = $this->receiptIntegration(User::factory()->create());
    }

    #[Test]
    public function a_redelivered_email_creates_one_receipt_and_one_extraction(): void
    {
        $this->expectExtractions(1);

        $email = $this->email('<order-123@shop.example>');

        (new ProcessReceiptEmailJob($this->integration, null, $email))->handle();
        (new ProcessReceiptEmailJob($this->integration, null, $email))->handle();

        $this->assertSame(1, $this->receiptCount($this->integration));
    }

    #[Test]
    public function the_message_id_identifies_the_email_whatever_its_s3_key(): void
    {
        $this->expectExtractions(1);

        $email = $this->email('<Order-456@Shop.Example>');

        (new ProcessReceiptEmailJob($this->integration, 'receipts/a.eml', $email))->handle();
        (new ProcessReceiptEmailJob($this->integration, 'receipts/b.eml', $this->email('order-456@shop.example')))->handle();

        $this->assertSame(1, $this->receiptCount($this->integration));
    }

    #[Test]
    public function a_deleted_receipt_is_not_recreated_by_a_redelivery(): void
    {
        $this->expectExtractions(1);

        $email = $this->email('<order-789@shop.example>');

        (new ProcessReceiptEmailJob($this->integration, null, $email))->handle();
        Event::where('integration_id', $this->integration->id)->first()->delete();
        (new ProcessReceiptEmailJob($this->integration, null, $email))->handle();

        $this->assertSame(0, $this->receiptCount($this->integration));
    }

    #[Test]
    public function different_emails_create_different_receipts(): void
    {
        $this->expectExtractions(2);

        (new ProcessReceiptEmailJob($this->integration, null, $this->email('<one@shop.example>')))->handle();
        (new ProcessReceiptEmailJob($this->integration, null, $this->email('<two@shop.example>')))->handle();

        $this->assertSame(2, $this->receiptCount($this->integration));
    }

    #[Test]
    public function the_same_email_for_another_user_is_their_own_receipt(): void
    {
        $this->expectExtractions(2);

        $other = $this->receiptIntegration(User::factory()->create());
        $email = $this->email('<shared@shop.example>');

        (new ProcessReceiptEmailJob($this->integration, null, $email))->handle();
        (new ProcessReceiptEmailJob($other, null, $email))->handle();

        $this->assertSame(1, $this->receiptCount($this->integration));
        $this->assertSame(1, $this->receiptCount($other));
    }

    #[Test]
    public function an_email_without_a_message_id_is_identified_by_its_content(): void
    {
        $this->expectExtractions(1);

        $email = $this->email(null);

        (new ProcessReceiptEmailJob($this->integration, null, $email))->handle();
        (new ProcessReceiptEmailJob($this->integration, null, $email))->handle();

        $this->assertSame(1, $this->receiptCount($this->integration));
    }

    #[Test]
    public function matching_is_left_to_the_task_pipeline(): void
    {
        $this->expectExtractions(1);

        (new ProcessReceiptEmailJob($this->integration, null, $this->email('<pipeline@shop.example>')))->handle();

        Queue::assertNotPushed(MatchReceiptToTransactionJob::class);
    }

    #[Test]
    public function local_receipt_time_is_normalized_for_the_event_blocks_and_matching_window(): void
    {
        $data = $this->receiptData();
        $data['transaction_metadata'] = [
            'transaction_date' => '2026-10-02T10:00:00',
            'transaction_timezone' => 'Europe/London',
        ];
        $data['line_items'] = [[
            'description' => 'Coffee', 'quantity' => 1, 'unit_price' => 1250,
            'total_price' => 1250, 'category' => 'food',
        ]];
        $this->mock(ReceiptExtractor::class, function ($mock) use ($data) {
            $mock->shouldReceive('extract')->once()->andReturn($data);
        });

        (new ProcessReceiptEmailJob($this->integration, null, $this->email('<local-time@shop.example>')))->handle();

        $event = Event::where('integration_id', $this->integration->id)->firstOrFail();
        $this->assertSame('2026-10-02T09:00:00+00:00', $event->time->toIso8601String());
        $this->assertSame('2026-10-02T07:00:00+00:00', $event->event_metadata['matching_hints']['suggested_date_range']['start']);
        $this->assertSame($data, $event->event_metadata['raw_extraction']);
        $this->assertSame('receipt', $event->event_metadata['time_resolution']['source']);
        $this->assertNotEmpty($event->blocks);
        foreach ($event->blocks as $block) {
            $this->assertTrue($block->time->equalTo($event->time));
        }
    }

    private function expectExtractions(int $times): void
    {
        $extractor = Mockery::mock(ReceiptExtractor::class);
        $extractor->shouldReceive('extract')->times($times)->andReturn($this->receiptData());
        $this->instance(ReceiptExtractor::class, $extractor);
    }

    private function receiptCount(Integration $integration): int
    {
        return Event::withTrashed()
            ->where('integration_id', $integration->id)
            ->where('action', 'had_receipt_from')
            ->whereNull('deleted_at')
            ->count();
    }

    private function receiptIntegration(User $user): Integration
    {
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'receipt']);

        return Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'receipt',
            'instance_type' => 'receipts',
        ]);
    }

    private function email(?string $messageId): string
    {
        $header = $messageId === null ? '' : "Message-ID: {$messageId}\r\n";

        return "From: Shop <orders@shop.example>\r\n"
            . "To: me@example.com\r\n"
            . "Subject: Your receipt\r\n"
            . "Date: Fri, 2 Oct 2026 10:00:00 +0100\r\n"
            . $header
            . "Content-Type: text/plain; charset=utf-8\r\n\r\n"
            . "Thanks for your order. Total: GBP 12.50\r\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function receiptData(): array
    {
        return [
            'is_valid_receipt' => true,
            'merchant' => ['name' => 'Shop'],
            'receipt_metadata' => [],
            'transaction_metadata' => ['transaction_date' => '2026-10-02T10:00:00+01:00'],
            'transaction_summary' => ['total_amount' => 1250, 'currency' => 'GBP'],
            'matching_hints' => [
                'suggested_amount' => 1250,
                'suggested_date_range' => ['start' => '2026-10-02T09:00:00+01:00', 'end' => '2026-10-02T11:00:00+01:00'],
            ],
            'line_items' => [],
        ];
    }
}
