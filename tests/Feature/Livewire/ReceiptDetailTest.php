<?php

namespace Tests\Feature\Livewire;

use App\Livewire\ReceiptDetail;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReceiptDetailTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    #[Test]
    public function detail_is_not_found_for_another_users_receipt(): void
    {
        $receipt = $this->receiptFor(User::factory()->create());

        $this->actingAs($this->user)
            ->get(route('receipts.show', $receipt->id))
            ->assertNotFound();
    }

    #[Test]
    public function owner_can_open_and_delete_their_receipt(): void
    {
        $receipt = $this->receiptFor($this->user);

        $this->actingAs($this->user);

        Livewire::test(ReceiptDetail::class, ['id' => $receipt->id])
            ->assertOk()
            ->call('deleteReceipt');

        $this->assertSoftDeleted('events', ['id' => $receipt->id]);
    }

    #[Test]
    public function an_expired_original_email_says_it_is_no_longer_kept(): void
    {
        Storage::fake('s3-receipts');
        $receipt = $this->receiptFor($this->user);
        $receipt->update(['event_metadata' => ['raw_email_s3_key' => 'receipts/expired.eml']]);

        $this->actingAs($this->user);

        Livewire::test(ReceiptDetail::class, ['id' => $receipt->id])
            ->call('downloadOriginalEmail')
            ->assertDispatched('notify', fn (string $event, array $params): bool => ($params[0]['message'] ?? null) === 'Original email no longer kept');
    }

    #[Test]
    public function a_kept_original_email_downloads(): void
    {
        Storage::fake('s3-receipts');
        Storage::disk('s3-receipts')->put('receipts/kept.eml', 'From: shop@example.com');
        $receipt = $this->receiptFor($this->user);
        $receipt->update(['event_metadata' => ['raw_email_s3_key' => 'receipts/kept.eml']]);

        $this->actingAs($this->user);

        Livewire::test(ReceiptDetail::class, ['id' => $receipt->id])
            ->call('downloadOriginalEmail')
            ->assertFileDownloaded('kept.eml');
    }

    private function receiptFor(User $user): Event
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'receipt',
        ]);
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'receipt',
        ]);

        return Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'receipt',
            'domain' => 'money',
            'action' => 'had_receipt_from',
        ]);
    }
}
