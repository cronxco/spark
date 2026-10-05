<?php

namespace Tests\Feature\Fetch;

use App\Models\EventObject;
use App\Models\User;
use App\Services\Fetch\BookmarkCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookmarkCreatorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BookmarkCreator $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->creator = app(BookmarkCreator::class);
    }

    #[Test]
    public function it_creates_a_bookmark_with_its_canonical_identity(): void
    {
        $result = $this->creator->firstOrCreate($this->user->id, 'https://Example.com/post/?utm_source=x', [], ['fetch_mode' => 'once']);

        $this->assertTrue($result['created']);
        $bookmark = $result['bookmark'];
        $this->assertSame('https://Example.com/post/?utm_source=x', $bookmark->url);
        $this->assertSame('https://example.com/post', $bookmark->metadata['canonical_url']);
        $this->assertSame('once', $bookmark->metadata['fetch_mode']);
        $this->assertSame('bookmark', $bookmark->concept);
        $this->assertSame('fetch_webpage', $bookmark->type);
    }

    #[Test]
    public function it_returns_the_existing_bookmark_for_a_tracking_variant(): void
    {
        $first = $this->creator->firstOrCreate($this->user->id, 'https://example.com/post')['bookmark'];

        $second = $this->creator->firstOrCreate($this->user->id, 'https://example.com/post?utm_medium=email#comments');

        $this->assertFalse($second['created']);
        $this->assertSame((string) $first->id, (string) $second['bookmark']?->id);
        $this->assertSame(1, EventObject::where('type', 'fetch_webpage')->count());
    }

    #[Test]
    public function it_matches_and_backfills_legacy_bookmarks_without_a_canonical_url(): void
    {
        $legacy = EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => 'Legacy',
            'url' => 'https://example.com/legacy/?utm_source=old',
            'time' => now(),
            'metadata' => ['fetch_mode' => 'recurring'],
        ]);

        $found = $this->creator->find($this->user->id, 'https://example.com/legacy');

        $this->assertSame((string) $legacy->id, (string) $found?->id);
        $this->assertSame('https://example.com/legacy', $legacy->fresh()->metadata['canonical_url']);
        $this->assertSame('recurring', $legacy->fresh()->metadata['fetch_mode']);
    }

    #[Test]
    public function it_finds_many_bookmarks_keyed_by_canonical_url(): void
    {
        $a = $this->creator->firstOrCreate($this->user->id, 'https://example.com/a')['bookmark'];
        $b = $this->creator->firstOrCreate($this->user->id, 'https://example.com/b?utm_source=x')['bookmark'];

        $found = $this->creator->findMany($this->user->id, [
            'https://example.com/a#x',
            'https://example.com/b',
            'https://example.com/c',
        ]);

        $this->assertSame(['https://example.com/a', 'https://example.com/b'], array_keys($found));
        $this->assertSame((string) $a->id, (string) $found['https://example.com/a']?->id);
        $this->assertSame((string) $b->id, (string) $found['https://example.com/b']?->id);
    }

    #[Test]
    public function it_scopes_bookmarks_to_the_user(): void
    {
        $this->creator->firstOrCreate(User::factory()->create()->id, 'https://example.com/shared');

        $result = $this->creator->firstOrCreate($this->user->id, 'https://example.com/shared');

        $this->assertTrue($result['created']);
        $this->assertSame(2, EventObject::where('type', 'fetch_webpage')->count());
    }
}
