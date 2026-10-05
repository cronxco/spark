<?php

namespace Tests\Feature\Fetch;

use App\Models\EventObject;
use App\Models\User;
use App\Services\Fetch\FetchMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FetchMetadataTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_preserves_keys_written_through_a_stale_instance(): void
    {
        $bookmark = $this->bookmark(['fetch_count' => 0]);
        $staleCopy = EventObject::find($bookmark->id);

        FetchMetadata::merge($bookmark, ['list_detection' => ['kind' => 'list']]);
        FetchMetadata::merge($staleCopy, ['last_checked_at' => '2026-09-27T10:00:00+00:00']);

        $metadata = $bookmark->fresh()->metadata;
        $this->assertSame(['kind' => 'list'], $metadata['list_detection']);
        $this->assertSame('2026-09-27T10:00:00+00:00', $metadata['last_checked_at']);
        $this->assertSame(0, $metadata['fetch_count']);
    }

    #[Test]
    public function it_applies_mutations_to_the_current_row_and_refreshes_the_instance(): void
    {
        $bookmark = $this->bookmark(['fetch_count' => 1]);
        EventObject::whereKey($bookmark->id)->update(['metadata' => ['fetch_count' => 5]]);

        FetchMetadata::mutate($bookmark, fn (array $metadata): array => array_merge($metadata, [
            'fetch_count' => $metadata['fetch_count'] + 1,
        ]), ['title' => 'Updated title']);

        $this->assertSame(6, $bookmark->metadata['fetch_count']);
        $this->assertSame('Updated title', $bookmark->title);
        $this->assertFalse($bookmark->isDirty());
        $this->assertSame(6, $bookmark->fresh()->metadata['fetch_count']);
    }

    #[Test]
    public function it_mutates_unsaved_models_in_memory(): void
    {
        $bookmark = new EventObject(['metadata' => ['a' => 1]]);

        FetchMetadata::merge($bookmark, ['b' => 2]);

        $this->assertSame(['a' => 1, 'b' => 2], $bookmark->metadata);
        $this->assertFalse($bookmark->exists);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function bookmark(array $metadata): EventObject
    {
        return EventObject::create([
            'user_id' => User::factory()->create()->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => 'https://example.com/a',
            'url' => 'https://example.com/a',
            'time' => now(),
            'metadata' => $metadata,
        ]);
    }
}
