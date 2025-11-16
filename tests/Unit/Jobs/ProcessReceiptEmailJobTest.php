<?php

namespace Tests\Unit\Jobs;

use App\Integrations\Receipt\ReceiptExtractor;
use App\Jobs\Data\Receipt\MatchReceiptToTransactionJob;
use App\Jobs\Data\Receipt\ProcessReceiptEmailJob;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessReceiptEmailJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'receipt',
        ]);

        Storage::fake('s3-receipts');
    }

    /** @test */
    public function it_processes_receipt_email_and_creates_event()
    {
        Queue::fake();

        // Mock extracted receipt data
        $extractedData = [
            'merchant_name' => 'Tesco Express',
            'transaction_date' => now()->toDateTimeString(),
            'total_amount' => 2599, // £25.99 in pence
            'currency' => 'GBP',
            'line_items' => [
                [
                    'description' => 'Milk',
                    'quantity' => 2,
                    'unit_price' => 125,
                    'total_price' => 250,
                    'category' => 'Dairy',
                ],
                [
                    'description' => 'Bread',
                    'quantity' => 1,
                    'unit_price' => 149,
                    'total_price' => 149,
                    'category' => 'Bakery',
                ],
            ],
            'tax_breakdown' => [],
            'payment_info' => [
                'method' => 'Card',
                'last_four' => '1234',
                'card_type' => 'Visa',
            ],
            'matching_hints' => [
                'suggested_amount' => 2599,
                'suggested_merchant_names' => ['Tesco', 'Tesco Express'],
                'card_last_four' => '1234',
                'time_window_minutes' => 30,
            ],
        ];

        // Create the email content
        $emailContent = $this->createMockEmail('receipt@tesco.com', 'Your Tesco Receipt');

        Storage::disk('s3-receipts')->put('test-receipt.eml', $emailContent);

        // Mock the extractor
        $this->mock(ReceiptExtractor::class, function ($mock) use ($extractedData) {
            $mock->shouldReceive('extract')
                ->once()
                ->andReturn($extractedData);
        });

        $job = new ProcessReceiptEmailJob($this->integration, 'test-receipt.eml');
        $job->handle();

        // Assert merchant EventObject created
        $this->assertDatabaseHas('event_objects', [
            'user_id' => $this->user->id,
            'concept' => 'merchant',
            'type' => 'receipt_received_from',
            'title' => 'Tesco Express',
        ]);

        // Assert receipt Event created
        $this->assertDatabaseHas('events', [
            'user_id' => $this->user->id,
            'integration_id' => $this->integration->id,
            'service' => 'receipt',
            'domain' => 'money',
            'action' => 'receipt_received_from',
            'value' => 2599,
            'value_unit' => 'GBP',
        ]);

        // Assert matching job dispatched
        Queue::assertPushed(MatchReceiptToTransactionJob::class);
    }

    /** @test */
    public function it_stores_extracted_data_in_merchant_metadata()
    {
        Queue::fake();

        $extractedData = [
            'merchant_name' => 'Sainsburys',
            'transaction_date' => now()->toDateTimeString(),
            'total_amount' => 1500,
            'currency' => 'GBP',
            'line_items' => [],
            'tax_breakdown' => [],
            'payment_info' => [],
            'matching_hints' => [
                'suggested_amount' => 1500,
                'suggested_merchant_names' => ['Sainsburys'],
            ],
        ];

        Storage::disk('s3-receipts')->put('test-receipt-2.eml', $this->createMockEmail('noreply@sainsburys.co.uk', 'Receipt'));

        $this->mock(ReceiptExtractor::class, function ($mock) use ($extractedData) {
            $mock->shouldReceive('extract')->once()->andReturn($extractedData);
        });

        $job = new ProcessReceiptEmailJob($this->integration, 'test-receipt-2.eml');
        $job->handle();

        $merchant = EventObject::where('user_id', $this->user->id)
            ->where('concept', 'merchant')
            ->where('title', 'Sainsburys')
            ->first();

        $this->assertNotNull($merchant);
        $this->assertArrayHasKey('extracted_data', $merchant->metadata);
        $this->assertEquals($extractedData, $merchant->metadata['extracted_data']);
        $this->assertFalse($merchant->metadata['is_matched']);
        $this->assertFalse($merchant->metadata['needs_review'] ?? false);
    }

    /** @test */
    public function it_handles_non_english_receipts()
    {
        Queue::fake();

        $extractedData = [
            'merchant_name' => 'Carrefour',
            'transaction_date' => now()->toDateTimeString(),
            'total_amount' => 3250,
            'currency' => 'EUR',
            'line_items' => [],
            'tax_breakdown' => [],
            'payment_info' => [],
            'matching_hints' => [
                'suggested_amount' => 3250,
                'suggested_merchant_names' => ['Carrefour'],
            ],
            'original_language' => 'fr', // French receipt
        ];

        Storage::disk('s3-receipts')->put('test-receipt-fr.eml', $this->createMockEmail('recu@carrefour.fr', 'Votre reçu'));

        $this->mock(ReceiptExtractor::class, function ($mock) use ($extractedData) {
            $mock->shouldReceive('extract')->once()->andReturn($extractedData);
        });

        $job = new ProcessReceiptEmailJob($this->integration, 'test-receipt-fr.eml');
        $job->handle();

        $merchant = EventObject::where('title', 'Carrefour')->first();

        $this->assertEquals('fr', $merchant->metadata['original_language']);
    }

    /** @test */
    public function it_creates_line_item_blocks()
    {
        Queue::fake();

        $extractedData = [
            'merchant_name' => 'Waitrose',
            'transaction_date' => now()->toDateTimeString(),
            'total_amount' => 1850,
            'currency' => 'GBP',
            'line_items' => [
                [
                    'description' => 'Organic Apples',
                    'quantity' => 3,
                    'unit_price' => 250,
                    'total_price' => 750,
                    'category' => 'Fruit',
                ],
                [
                    'description' => 'Sourdough Bread',
                    'quantity' => 1,
                    'unit_price' => 350,
                    'total_price' => 350,
                    'category' => 'Bakery',
                ],
            ],
            'tax_breakdown' => [],
            'payment_info' => [],
            'matching_hints' => [
                'suggested_amount' => 1850,
                'suggested_merchant_names' => ['Waitrose'],
            ],
        ];

        Storage::disk('s3-receipts')->put('test-receipt-items.eml', $this->createMockEmail('receipts@waitrose.com', 'Receipt'));

        $this->mock(ReceiptExtractor::class, function ($mock) use ($extractedData) {
            $mock->shouldReceive('extract')->once()->andReturn($extractedData);
        });

        $job = new ProcessReceiptEmailJob($this->integration, 'test-receipt-items.eml');
        $job->handle();

        $receipt = Event::where('service', 'receipt')
            ->where('action', 'receipt_received_from')
            ->first();

        $this->assertNotNull($receipt);

        // Check blocks created for line items
        $blocks = $receipt->blocks;
        $this->assertGreaterThanOrEqual(2, $blocks->count());

        $appleBlock = $blocks->firstWhere('title', 'Organic Apples');
        $this->assertNotNull($appleBlock);
        $this->assertEquals(750, $appleBlock->value);
        $this->assertEquals('receipt_line_item', $appleBlock->type);
    }

    /** @test */
    public function it_stores_s3_key_in_metadata()
    {
        Queue::fake();

        $s3Key = 'receipts/2025/01/test-receipt.eml';

        $extractedData = [
            'merchant_name' => 'Aldi',
            'transaction_date' => now()->toDateTimeString(),
            'total_amount' => 999,
            'currency' => 'GBP',
            'line_items' => [],
            'tax_breakdown' => [],
            'payment_info' => [],
            'matching_hints' => [
                'suggested_amount' => 999,
                'suggested_merchant_names' => ['Aldi'],
            ],
        ];

        Storage::disk('s3-receipts')->put($s3Key, $this->createMockEmail('noreply@aldi.co.uk', 'Receipt'));

        $this->mock(ReceiptExtractor::class, function ($mock) use ($extractedData) {
            $mock->shouldReceive('extract')->once()->andReturn($extractedData);
        });

        $job = new ProcessReceiptEmailJob($this->integration, $s3Key);
        $job->handle();

        $merchant = EventObject::where('title', 'Aldi')->first();

        $this->assertEquals($s3Key, $merchant->metadata['s3_object_key']);
    }

    /** @test */
    public function it_handles_missing_s3_file()
    {
        $this->expectException(\Exception::class);

        $job = new ProcessReceiptEmailJob($this->integration, 'non-existent-file.eml');
        $job->handle();
    }

    private function createMockEmail(string $from, string $subject): string
    {
        return <<<EMAIL
From: {$from}
To: receipts@spark.cronx.co
Subject: {$subject}
Date: {now()->toRfc2822String()}
Content-Type: text/plain; charset=UTF-8

This is a mock email receipt.

Thank you for your purchase!

Total: £25.99
EMAIL;
    }
}
