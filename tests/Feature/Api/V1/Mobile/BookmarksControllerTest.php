<?php

namespace Tests\Feature\Api\V1\Mobile;

use App\Jobs\Fetch\FetchSingleUrl;
use App\Models\EventObject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookmarksControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);

        $this->user = User::factory()->create();
    }

    #[Test]
    public function requires_authentication(): void
    {
        $this->postJson('/api/v1/mobile/bookmarks', ['url' => 'https://example.com/article'])
            ->assertStatus(401);
    }

    #[Test]
    public function requires_write_ability(): void
    {
        Sanctum::actingAs($this->user, ['ios:read']);

        $this->postJson('/api/v1/mobile/bookmarks', ['url' => 'https://example.com/article'])
            ->assertStatus(403);
    }

    #[Test]
    public function stores_bookmark_and_dispatches_fetch_job(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/bookmarks', ['url' => 'https://example.com/article'])
            ->assertStatus(201)
            ->assertJsonPath('bookmark.url', 'https://example.com/article');

        $this->assertDatabaseHas('objects', [
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'url' => 'https://example.com/article',
        ]);

        Queue::assertPushed(FetchSingleUrl::class);
    }

    #[Test]
    public function duplicate_bookmark_does_not_create_a_second(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => 'Example Article',
            'url' => 'https://example.com/article',
            'time' => now(),
            'metadata' => ['domain' => 'example.com', 'enabled' => true],
        ]);

        $this->postJson('/api/v1/mobile/bookmarks', ['url' => 'https://example.com/article'])
            ->assertStatus(200);

        $this->assertSame(1, EventObject::where('url', 'https://example.com/article')->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function validates_url_format(): void
    {
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/bookmarks', ['url' => 'not-a-url'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['url']);
    }

    #[Test]
    public function rejects_unsafe_ssrf_targets(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user, ['ios:read', 'ios:write']);

        $this->postJson('/api/v1/mobile/bookmarks', ['url' => 'http://169.254.169.254/latest/meta-data/'])
            ->assertStatus(422);

        $this->assertSame(0, EventObject::query()->count());
        Queue::assertNothingPushed();
    }
}
