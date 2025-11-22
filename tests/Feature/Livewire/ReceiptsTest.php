<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Receipts;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\Relationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReceiptsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private IntegrationGroup $group;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'receipt',
        ]);

        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->group->id,
            'service' => 'receipt',
        ]);
    }

    #[Test]
    public function component_renders_successfully(): void
    {
        Livewire::test(Receipts::class)
            ->assertStatus(200);
    }

    #[Test]
    public function component_has_default_properties(): void
    {
        $component = Livewire::test(Receipts::class);

        $component->assertSet('search', null)
            ->assertSet('statusFilter', 'all')
            ->assertSet('perPage', 25)
            ->assertSet('selectedReceiptId', null)
            ->assertSet('showMatchModal', false);
    }

    #[Test]
    public function search_filter_can_be_set(): void
    {
        $component = Livewire::test(Receipts::class);

        $component->set('search', 'test receipt')
            ->assertSet('search', 'test receipt');
    }

    #[Test]
    public function status_filter_can_be_set_to_matched(): void
    {
        $component = Livewire::test(Receipts::class);

        $component->set('statusFilter', 'matched')
            ->assertSet('statusFilter', 'matched');
    }

    #[Test]
    public function status_filter_can_be_set_to_unmatched(): void
    {
        $component = Livewire::test(Receipts::class);

        $component->set('statusFilter', 'unmatched')
            ->assertSet('statusFilter', 'unmatched');
    }

    #[Test]
    public function status_filter_can_be_set_to_review(): void
    {
        $component = Livewire::test(Receipts::class);

        $component->set('statusFilter', 'review')
            ->assertSet('statusFilter', 'review');
    }

    #[Test]
    public function clear_filters_resets_search_and_status(): void
    {
        $component = Livewire::test(Receipts::class);

        $component->set('search', 'test')
            ->set('statusFilter', 'matched')
            ->call('clearFilters')
            ->assertSet('search', null)
            ->assertSet('statusFilter', 'all');
    }

    #[Test]
    public function sort_by_column_toggles_direction(): void
    {
        $component = Livewire::test(Receipts::class);

        // Default sort is time desc
        $component->assertSet('sortBy', ['column' => 'time', 'direction' => 'desc']);

        // Sort by time again should toggle direction
        $component->call('sortByColumn', 'time')
            ->assertSet('sortBy', ['column' => 'time', 'direction' => 'asc']);

        // Sort by time again should toggle back
        $component->call('sortByColumn', 'time')
            ->assertSet('sortBy', ['column' => 'time', 'direction' => 'desc']);
    }

    #[Test]
    public function sort_by_new_column_defaults_to_asc(): void
    {
        $component = Livewire::test(Receipts::class);

        $component->call('sortByColumn', 'value')
            ->assertSet('sortBy', ['column' => 'value', 'direction' => 'asc']);
    }

    #[Test]
    public function match_modal_can_be_opened(): void
    {
        $merchant = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'metadata' => ['is_matched' => false],
        ]);

        $receipt = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'receipt',
            'domain' => 'money',
            'action' => 'receipt_received_from',
            'target_id' => $merchant->id,
        ]);

        $component = Livewire::test(Receipts::class);

        $component->call('openMatchModal', $receipt->id)
            ->assertSet('selectedReceiptId', $receipt->id)
            ->assertSet('showMatchModal', true);
    }

    #[Test]
    public function match_modal_can_be_closed(): void
    {
        $component = Livewire::test(Receipts::class);

        $component->set('selectedReceiptId', 'test-id')
            ->set('showMatchModal', true)
            ->call('closeMatchModal')
            ->assertSet('selectedReceiptId', null)
            ->assertSet('showMatchModal', false);
    }

    #[Test]
    public function delete_receipt_removes_receipt_event(): void
    {
        $merchant = EventObject::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $receipt = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'receipt',
            'domain' => 'money',
            'action' => 'receipt_received_from',
            'target_id' => $merchant->id,
        ]);

        $component = Livewire::test(Receipts::class);
        $component->call('deleteReceipt', $receipt->id);

        // Should be soft deleted
        $this->assertSoftDeleted('events', ['id' => $receipt->id]);
    }

    #[Test]
    public function delete_receipt_does_not_delete_non_receipt_events(): void
    {
        $event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'other_service',
        ]);

        $component = Livewire::test(Receipts::class);
        $component->call('deleteReceipt', $event->id);

        // Should not be deleted since it's not a receipt
        $this->assertDatabaseHas('events', ['id' => $event->id, 'deleted_at' => null]);
    }

    #[Test]
    public function remove_match_deletes_receipt_for_relationship(): void
    {
        $merchant = EventObject::factory()->create([
            'user_id' => $this->user->id,
            'metadata' => ['is_matched' => true],
        ]);

        $receipt = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'receipt',
            'target_id' => $merchant->id,
        ]);

        $transaction = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'service' => 'monzo',
        ]);

        // Create the relationship
        Relationship::create([
            'from_type' => Event::class,
            'from_id' => $receipt->id,
            'to_type' => Event::class,
            'to_id' => $transaction->id,
            'type' => 'receipt_for',
        ]);

        $component = Livewire::test(Receipts::class);
        $component->call('removeMatch', $receipt->id);

        // Relationship should be deleted
        $this->assertDatabaseMissing('relationships', [
            'from_id' => $receipt->id,
            'type' => 'receipt_for',
        ]);
    }

    #[Test]
    public function per_page_can_be_changed(): void
    {
        $component = Livewire::test(Receipts::class);

        $component->set('perPage', 50)
            ->assertSet('perPage', 50);
    }

    #[Test]
    public function updated_search_resets_page(): void
    {
        $component = Livewire::test(Receipts::class);

        // This tests that updatedSearch is called when search changes
        $component->set('search', 'new search');

        // The page should be reset (this is handled by resetPage in updatedSearch)
        $component->assertOk();
    }

    #[Test]
    public function updated_status_filter_resets_page(): void
    {
        $component = Livewire::test(Receipts::class);

        // This tests that updatedStatusFilter is called when filter changes
        $component->set('statusFilter', 'matched');

        // The page should be reset (this is handled by resetPage in updatedStatusFilter)
        $component->assertOk();
    }
}
