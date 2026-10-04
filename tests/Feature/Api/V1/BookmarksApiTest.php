<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\Fetch\FetchSingleUrl;
use App\Models\EventObject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookmarksApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_requires_authentication(): void
    {
        $this->postJson('/api/v1/bookmarks', ['url' => 'https://example.com/article'])
            ->assertUnauthorized();
    }

    #[Test]
    public function a_bookmark_token_saves_a_url_and_queues_a_one_off_fetch(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['bookmark:write']);

        $this->postJson('/api/v1/bookmarks', ['url' => 'https://example.com/article'])
            ->assertCreated()
            ->assertJsonPath('state', 'queued')
            ->assertJsonPath('job_dispatched', true)
            ->assertJsonPath('bookmark.url', 'https://example.com/article');

        $bookmark = EventObject::query()->where('url', 'https://example.com/article')->firstOrFail();
        $this->assertSame('once', $bookmark->metadata['fetch_mode']);
        $this->assertSame('api', $bookmark->metadata['subscription_source']);
        Queue::assertPushed(FetchSingleUrl::class);
    }

    #[Test]
    public function a_data_write_token_is_still_accepted(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create(), ['data:write']);

        $this->postJson('/api/v1/bookmarks', ['url' => 'https://example.com/article'])
            ->assertCreated();
    }

    #[Test]
    public function a_token_without_either_capability_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['data:read']);

        $this->postJson('/api/v1/bookmarks', ['url' => 'https://example.com/article'])
            ->assertForbidden()
            ->assertJsonPath('required_ability', 'bookmark:write');
    }

    #[Test]
    public function fetch_immediately_false_saves_without_queueing(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create(), ['bookmark:write']);

        $this->postJson('/api/v1/bookmarks', ['url' => 'https://example.com/article', 'fetch_immediately' => false])
            ->assertCreated()
            ->assertJsonPath('job_dispatched', false);

        $this->assertDatabaseHas('objects', ['url' => 'https://example.com/article']);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function fetch_mode_recurring_is_stored(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create(), ['bookmark:write']);

        $this->postJson('/api/v1/bookmarks', ['url' => 'https://example.com/article', 'fetch_mode' => 'recurring'])
            ->assertCreated();

        $bookmark = EventObject::query()->where('url', 'https://example.com/article')->firstOrFail();
        $this->assertSame('recurring', $bookmark->metadata['fetch_mode']);
    }

    #[Test]
    public function an_existing_bookmark_returns_200_and_force_refresh_queues_again(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create(), ['bookmark:write']);
        $this->postJson('/api/v1/bookmarks', ['url' => 'https://example.com/article', 'fetch_immediately' => false])
            ->assertCreated();

        $this->postJson('/api/v1/bookmarks', ['url' => 'https://example.com/article'])
            ->assertOk()
            ->assertJsonPath('job_dispatched', false);
        Queue::assertNothingPushed();

        $this->postJson('/api/v1/bookmarks', ['url' => 'https://example.com/article', 'force_refresh' => true])
            ->assertOk()
            ->assertJsonPath('job_dispatched', true);
        Queue::assertPushed(FetchSingleUrl::class);
        $this->assertSame(1, EventObject::query()->where('url', 'https://example.com/article')->count());
    }

    #[Test]
    public function it_rejects_invalid_input_and_unsafe_targets(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->create(), ['bookmark:write']);

        $this->postJson('/api/v1/bookmarks', [])->assertUnprocessable()->assertJsonValidationErrors(['url']);
        $this->postJson('/api/v1/bookmarks', ['url' => 'not a url'])->assertUnprocessable();
        $this->postJson('/api/v1/bookmarks', ['url' => 'https://example.com/a', 'fetch_mode' => 'hourly'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fetch_mode']);
        $this->postJson('/api/v1/bookmarks', ['url' => 'http://169.254.169.254/latest/meta-data/'])
            ->assertUnprocessable();

        $this->assertSame(0, EventObject::query()->count());
        Queue::assertNothingPushed();
    }
}
