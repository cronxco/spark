<?php

namespace Tests\Feature\Api;

use App\Jobs\Fetch\FetchSingleUrl;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FetchApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function unauthenticated_users_cannot_bookmark_urls()
    {
        $response = $this->postJson('/api/fetch/bookmarks', [
            'url' => 'https://example.com/article',
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function authenticated_user_can_bookmark_url()
    {
        Queue::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['bookmark:write']);

        // Create Fetch integration for the user
        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'fetch',
            'name' => 'Fetch',
            'instance_type' => 'fetcher',
        ]);

        $response = $this->postJson('/api/fetch/bookmarks', [
            'url' => 'https://example.com/article',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'job_dispatched' => true,
        ]);

        $response->assertJsonStructure([
            'success',
            'bookmark' => [
                'id',
                'url',
                'title',
                'status',
                'created_at',
            ],
            'job_dispatched',
        ]);

        // Verify bookmark was created in database
        $this->assertDatabaseHas('objects', [
            'user_id' => $user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'url' => 'https://example.com/article',
        ]);

        // Verify job was dispatched
        Queue::assertPushed(FetchSingleUrl::class);
    }

    #[Test]
    public function bookmark_url_validates_url_format()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['bookmark:write']);

        $response = $this->postJson('/api/fetch/bookmarks', [
            'url' => 'not-a-valid-url',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['url']);
    }

    #[Test]
    public function bookmark_url_requires_url_parameter()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['bookmark:write']);

        $response = $this->postJson('/api/fetch/bookmarks', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['url']);
    }

    #[Test]
    public function duplicate_bookmark_returns_existing()
    {
        Queue::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['bookmark:write']);

        // Create existing bookmark
        $existingBookmark = EventObject::create([
            'user_id' => $user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => 'Example Article',
            'url' => 'https://example.com/article',
            'time' => now(),
            'metadata' => [
                'domain' => 'example.com',
                'enabled' => true,
            ],
        ]);

        // Attempt to create duplicate
        $response = $this->postJson('/api/fetch/bookmarks', [
            'url' => 'https://example.com/article',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'job_dispatched' => false,
            'message' => 'Bookmark already exists',
            'bookmark' => [
                'id' => $existingBookmark->id,
                'url' => 'https://example.com/article',
            ],
        ]);

        // Verify no new bookmark was created
        $this->assertEquals(1, EventObject::where('url', 'https://example.com/article')->count());

        // Verify job was NOT dispatched
        Queue::assertNothingPushed();
    }

    #[Test]
    public function bookmark_url_respects_fetch_immediately_false()
    {
        Queue::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['bookmark:write']);

        $response = $this->postJson('/api/fetch/bookmarks', [
            'url' => 'https://example.com/article',
            'fetch_immediately' => false,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'job_dispatched' => false,
        ]);

        // Verify bookmark was created
        $this->assertDatabaseHas('objects', [
            'url' => 'https://example.com/article',
        ]);

        // Verify job was NOT dispatched
        Queue::assertNothingPushed();
    }

    #[Test]
    public function bookmark_url_defaults_to_fetch_immediately_true()
    {
        Queue::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['bookmark:write']);

        // Create Fetch integration for the user
        Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'fetch',
            'name' => 'Fetch',
            'instance_type' => 'fetcher',
        ]);

        $response = $this->postJson('/api/fetch/bookmarks', [
            'url' => 'https://example.com/article',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'job_dispatched' => true,
        ]);

        // Verify job was dispatched (default behavior)
        Queue::assertPushed(FetchSingleUrl::class);
    }

    #[Test]
    public function bookmark_url_includes_api_subscription_source()
    {
        Queue::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['bookmark:write']);

        // Create Fetch integration for the user
        Integration::factory()->create([
            'user_id' => $user->id,
            'service' => 'fetch',
            'name' => 'Fetch',
            'instance_type' => 'fetcher',
        ]);

        $response = $this->postJson('/api/fetch/bookmarks', [
            'url' => 'https://example.com/article',
        ]);

        $response->assertStatus(200);

        // Verify metadata includes subscription_source = 'api'
        $bookmark = EventObject::where('url', 'https://example.com/article')->first();
        $this->assertEquals('api', $bookmark->metadata['subscription_source']);
    }

    #[Test]
    public function bookmark_url_rejects_unsafe_ssrf_targets()
    {
        Queue::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['bookmark:write']);

        $response = $this->postJson('/api/fetch/bookmarks', [
            'url' => 'http://169.254.169.254/latest/meta-data/',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['url']);

        $this->assertEquals(0, EventObject::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function bookmark_url_auto_creates_fetch_integration_and_queues()
    {
        Queue::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['bookmark:write']);

        // No Fetch integration pre-created
        $response = $this->postJson('/api/fetch/bookmarks', [
            'url' => 'https://example.com/article',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'state' => 'queued',
            'job_dispatched' => true,
        ]);

        $this->assertDatabaseHas('integrations', [
            'user_id' => $user->id,
            'service' => 'fetch',
            'instance_type' => 'fetcher',
        ]);

        $bookmark = EventObject::where('url', 'https://example.com/article')->first();
        $this->assertNotNull($bookmark->metadata['fetch_integration_id']);
        $this->assertEquals('once', $bookmark->metadata['fetch_mode']);

        Queue::assertPushed(FetchSingleUrl::class);
    }

    #[Test]
    public function bookmark_url_requires_bookmark_write_ability()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['some:other-ability']);

        $response = $this->postJson('/api/fetch/bookmarks', [
            'url' => 'https://example.com/article',
        ]);

        $response->assertStatus(403);
    }

    #[Test]
    public function bookmark_url_allows_scoped_and_legacy_tokens()
    {
        Queue::fake();

        $user = User::factory()->create();

        // Scoped token
        Sanctum::actingAs($user, ['bookmark:write']);
        $this->postJson('/api/fetch/bookmarks', ['url' => 'https://example.com/a'])
            ->assertStatus(200);

        // Legacy wildcard token (created before scoping) still works
        Sanctum::actingAs($user, ['*']);
        $this->postJson('/api/fetch/bookmarks', ['url' => 'https://example.com/b'])
            ->assertStatus(200);
    }
}
