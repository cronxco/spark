<?php

namespace Tests\Feature\Fetch;

use App\Jobs\Fetch\FetchSingleUrl;
use App\Livewire\BookmarkUrl;
use App\Models\EventObject;
use App\Models\User;
use App\Services\Fetch\UrlSafetyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpotlightBookmarkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->mock(UrlSafetyValidator::class, function ($mock): void {
            $mock->shouldReceive('validate')->andReturnNull();
        });
    }

    #[Test]
    public function it_creates_a_bookmark_with_a_canonical_identity(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(BookmarkUrl::class)
            ->set('url', 'https://example.com/post?utm_source=share')
            ->call('save')
            ->assertHasNoErrors();

        $bookmark = EventObject::where('type', 'fetch_webpage')->sole();
        $this->assertSame('https://example.com/post', $bookmark->metadata['canonical_url']);
        $this->assertSame('spotlight', $bookmark->metadata['added_via']);
        Queue::assertPushed(FetchSingleUrl::class);
    }

    #[Test]
    public function it_reuses_an_existing_bookmark_for_a_tracking_variant(): void
    {
        $user = User::factory()->create();

        foreach (['https://example.com/post', 'https://example.com/post?utm_source=share'] as $url) {
            Livewire::actingAs($user)
                ->test(BookmarkUrl::class)
                ->set('url', $url)
                ->set('fetchMode', 'once')
                ->call('save')
                ->assertHasNoErrors();
        }

        $bookmark = EventObject::where('type', 'fetch_webpage')->sole();
        $this->assertSame('https://example.com/post', $bookmark->url);
        $this->assertSame('once', $bookmark->metadata['fetch_mode']);
        Queue::assertPushed(FetchSingleUrl::class, fn (FetchSingleUrl $job): bool => $job->url === 'https://example.com/post');
    }
}
