<?php

namespace Tests\Feature\Console;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RepairReceiptTimezonesTest extends TestCase
{
    #[Test]
    public function dry_run_is_the_default_and_does_not_change_receipts_or_blocks(): void
    {
        $event = $this->receipt();
        $block = $event->createBlock(['block_type' => 'receipt_line_item', 'title' => 'Coffee', 'time' => $event->time]);
        $originalMetadata = $event->event_metadata;

        $this->artisan('receipts:repair-timezones', ['--timezone' => 'Europe/London'])
            ->expectsOutputToContain($event->id)->assertSuccessful();

        $this->assertTrue($event->fresh()->time->equalTo($event->time));
        $this->assertTrue($block->fresh()->time->equalTo($event->time));
        $this->assertSame($originalMetadata, $event->fresh()->event_metadata);
    }

    #[Test]
    public function apply_repairs_receipt_blocks_and_hints_once_and_retains_the_original_data(): void
    {
        $event = $this->receipt();
        $item = $event->createBlock(['block_type' => 'receipt_line_item', 'title' => 'Coffee', 'time' => $event->time]);
        $deleted = $event->createBlock(['block_type' => 'receipt_payment_method', 'title' => 'Payment', 'time' => $event->time]);
        $deleted->delete();
        $edited = $event->createBlock(['block_type' => 'receipt_tax_summary', 'title' => 'Edited', 'time' => $event->time->copy()->subMinutes(20)]);
        $derived = $event->createBlock(['block_type' => 'custom', 'title' => 'Other', 'time' => $event->time]);
        $raw = $event->event_metadata['raw_extraction'];

        $this->artisan('receipts:repair-timezones', ['--timezone' => 'Europe/London', '--apply' => true])->assertSuccessful();
        $fixed = $event->fresh();
        $this->assertSame('2026-10-04T13:50:14+00:00', $fixed->time->toIso8601String());
        $this->assertSame('2026-10-04T13:50:55+00:00', $fixed->created_at->toIso8601String());
        $this->assertTrue($item->fresh()->time->equalTo($fixed->time));
        $this->assertTrue($deleted->fresh()->time->equalTo($fixed->time));
        $this->assertTrue($deleted->fresh()->trashed());
        $this->assertTrue($edited->fresh()->time->equalTo($edited->time));
        $this->assertTrue($derived->fresh()->time->equalTo($derived->time));
        $this->assertSame($raw, $fixed->event_metadata['raw_extraction']);
        $this->assertSame('2026-10-04T14:50:14+00:00', $fixed->event_metadata['receipt_timezone_repair']['original_time']);
        $this->assertSame('2026-10-04T11:50:14+00:00', $fixed->event_metadata['matching_hints']['suggested_date_range']['start']);

        $this->artisan('receipts:repair-timezones', ['--timezone' => 'Europe/London', '--apply' => true])->assertSuccessful();
        $this->assertSame($fixed->event_metadata, $event->fresh()->event_metadata);
        $this->assertTrue($event->fresh()->time->equalTo($fixed->time));
    }

    #[Test]
    public function it_skips_explicit_offsets_normalized_dates_edited_events_and_delayed_imports(): void
    {
        $offset = $this->receipt(['transaction_metadata' => ['transaction_date' => '2026-10-04T14:50:14+01:00']]);
        $normalized = $this->receipt(['time_resolution' => ['source' => 'receipt']]);
        $edited = $this->receipt();
        $edited->update(['time' => $edited->time->copy()->addMinute()]);
        $delayed = $this->receipt();
        $delayed->forceFill(['created_at' => Carbon::parse('2026-10-05T13:00:00Z')])->save();
        $this->assertSame('2026-10-05T13:00:00+00:00', $delayed->fresh()->created_at->toIso8601String());
        $conflict = $this->receipt(['transaction_metadata' => ['transaction_date' => '2026-10-04T14:50:14Z', 'transaction_timezone' => 'America/New_York']]);
        $ambiguous = $this->receipt(['transaction_metadata' => ['transaction_date' => '2026-10-25T01:30:00Z']]);
        $ambiguous->forceFill(['time' => Carbon::parse('2026-10-25T01:30:00Z'), 'created_at' => Carbon::parse('2026-10-25T00:31:00Z')])->save();
        $this->assertSame('2026-10-25T00:31:00+00:00', $ambiguous->fresh()->created_at->toIso8601String());
        $events = [$offset, $normalized, $edited, $delayed, $conflict, $ambiguous];
        $before = collect($events)->mapWithKeys(fn ($event) => [$event->id => $event->fresh()->time->toIso8601String()]);

        $this->artisan('receipts:repair-timezones', ['--timezone' => 'Europe/London', '--apply' => true])->assertSuccessful();

        foreach ($events as $event) {
            $this->assertSame($before[$event->id], $event->fresh()->time->toIso8601String());
            $this->assertArrayNotHasKey('receipt_timezone_repair', $event->fresh()->event_metadata);
        }
    }

    #[Test]
    public function user_and_event_filters_limit_the_repair_scope(): void
    {
        $selected = $this->receipt();
        $other = $this->receipt();

        $this->artisan('receipts:repair-timezones', [
            '--timezone' => 'Europe/London', '--apply' => true,
            '--user' => $selected->integration->user_id, '--event' => [$selected->id],
        ])->assertSuccessful();

        $this->assertArrayHasKey('receipt_timezone_repair', $selected->fresh()->event_metadata);
        $this->assertArrayNotHasKey('receipt_timezone_repair', $other->fresh()->event_metadata);
    }

    #[Test]
    public function it_requires_a_timezone_and_rejects_conflicting_modes(): void
    {
        $this->artisan('receipts:repair-timezones')->assertFailed();
        $this->artisan('receipts:repair-timezones', ['--timezone' => 'invalid'])->assertFailed();
        $this->artisan('receipts:repair-timezones', ['--timezone' => 'Europe/London', '--apply' => true, '--dry-run' => true])->assertFailed();
    }

    private function receipt(array $extraMetadata = []): Event
    {
        Queue::fake();
        $user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $user->id, 'service' => 'receipt']);
        $integration = Integration::factory()->create([
            'user_id' => $user->id, 'integration_group_id' => $group->id,
            'service' => 'receipt', 'instance_type' => 'receipts',
        ]);
        $actor = EventObject::factory()->create(['user_id' => $user->id]);
        $merchant = EventObject::factory()->create(['user_id' => $user->id]);
        $metadata = array_replace([
            'transaction_metadata' => ['transaction_date' => '2026-10-04T14:50:14Z'],
            'matching_hints' => ['suggested_amount' => 2185],
        ], $extraMetadata);
        $metadata['raw_extraction'] = ['transaction_metadata' => $metadata['transaction_metadata']];

        return Event::factory()->create([
            'integration_id' => $integration->id, 'actor_id' => $actor->id, 'target_id' => $merchant->id,
            'service' => 'receipt', 'domain' => 'money', 'action' => 'had_receipt_from',
            'value' => 2185, 'value_multiplier' => 100, 'value_unit' => 'GBP',
            'time' => Carbon::parse('2026-10-04T14:50:14Z'),
            'created_at' => Carbon::parse('2026-10-04T13:50:55Z'), 'event_metadata' => $metadata,
        ]);
    }
}
