<?php

namespace Tests\Feature\Integrations\Karakeep;

use App\Jobs\Data\Karakeep\KarakeepBookmarkData;
use App\Jobs\Data\Karakeep\KarakeepBookmarksData;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class KarakeepUnchangedBookmarkDispatchTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function only_new_or_changed_bookmarks_are_dispatched(): void
    {
        Queue::fake();
        $integration = Integration::factory()->create(['service' => 'karakeep']);
        foreach (['unchanged', 'edited', 'listed'] as $id) {
            $object = EventObject::withoutEvents(fn () => EventObject::factory()->create([
                'user_id' => $integration->user_id,
                'concept' => 'bookmark',
                'type' => 'karakeep_bookmark',
                'metadata' => ['karakeep_id' => $id, 'updated_at' => '2026-10-01T00:00:00.000Z'],
            ]));
            Event::withoutEvents(fn () => Event::factory()->create([
                'integration_id' => $integration->id,
                'service' => 'karakeep',
                'source_id' => "karakeep_bookmark_{$id}",
                'target_id' => $object->id,
            ]));
        }

        $bookmarks = [
            ['id' => 'unchanged', 'modifiedAt' => '2026-10-01T00:00:00.000Z'],
            ['id' => 'edited', 'modifiedAt' => '2026-10-09T00:00:00.000Z'],
            ['id' => 'listed', 'modifiedAt' => '2026-10-01T00:00:00.000Z', 'lists' => ['reading']],
            ['id' => 'brand-new', 'modifiedAt' => '2026-10-09T00:00:00.000Z'],
        ];

        (new KarakeepBookmarksData($integration, ['bookmarks' => $bookmarks]))->handle();

        $dispatched = [];
        Queue::assertPushed(KarakeepBookmarkData::class, function (KarakeepBookmarkData $job) use (&$dispatched) {
            $dispatched[] = (fn () => $this->rawData['id'])->call($job);

            return true;
        });
        sort($dispatched);
        $this->assertSame(['brand-new', 'edited', 'listed'], $dispatched);
    }
}
