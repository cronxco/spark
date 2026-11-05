<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\Data\Fetch\ProcessFetchedContent;
use App\Jobs\Fetch\FetchSingleUrl;
use App\Models\EventObject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CapturedBookmarksApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_requires_authentication(): void
    {
        $this->postJson('/api/v1/bookmarks/capture', $this->payload())
            ->assertUnauthorized();
    }

    #[Test]
    public function it_requires_the_bookmark_write_ability(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['data:write']);

        $this->postJson('/api/v1/bookmarks/capture', $this->payload())
            ->assertForbidden()
            ->assertJsonPath('required_ability', 'bookmark:write');
    }

    #[Test]
    public function it_captures_rendered_html_without_refetching_the_url(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['bookmark:write']);

        $response = $this->postJson('/api/v1/bookmarks/capture', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('state', 'captured')
            ->assertJsonPath('bookmark.url', 'https://example.com/private-article')
            ->assertJsonPath('bookmark.title', 'The captured article title');

        $bookmark = EventObject::query()
            ->where('user_id', $user->id)
            ->where('url', 'https://example.com/private-article')
            ->firstOrFail();

        $this->assertSame('browser_extension', $bookmark->metadata['subscription_source']);
        $this->assertSame('rendered_dom', $bookmark->metadata['last_capture_method']);
        $this->assertNotEmpty($bookmark->metadata['last_capture_at']);

        Queue::assertNotPushed(FetchSingleUrl::class);
        Queue::assertPushed(ProcessFetchedContent::class, function (ProcessFetchedContent $job) use ($bookmark): bool {
            return $job->webpage->is($bookmark)
                && $job->extracted['title'] === 'The captured article title'
                && str_contains($job->extracted['text_content'], 'authenticated browser session');
        });
    }

    #[Test]
    public function it_accepts_full_content_when_paywall_markup_remains_in_the_dom(): void
    {
        Queue::fake();

        Sanctum::actingAs(User::factory()->create(), ['bookmark:write']);

        $payload = $this->payload();
        $payload['html'] = str_replace(
            '</article>',
            '</article><div class="paywall">Already a subscriber? Sign in to read.</div>',
            $payload['html'],
        );

        $this->postJson('/api/v1/bookmarks/capture', $payload)
            ->assertCreated()
            ->assertJsonPath('state', 'captured');

        Queue::assertPushed(ProcessFetchedContent::class);
    }

    #[Test]
    public function it_reuses_an_existing_bookmark_when_recapturing(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['bookmark:write']);

        $firstId = $this->postJson('/api/v1/bookmarks/capture', $this->payload())
            ->assertCreated()
            ->json('bookmark.id');

        $this->postJson('/api/v1/bookmarks/capture', $this->payload())
            ->assertOk()
            ->assertJsonPath('state', 'recaptured')
            ->assertJsonPath('bookmark.id', $firstId);

        $this->assertSame(1, EventObject::query()
            ->where('user_id', $user->id)
            ->where('url', 'https://example.com/private-article')
            ->count());
    }

    #[Test]
    public function it_does_not_create_a_bookmark_when_content_cannot_be_extracted(): void
    {
        Queue::fake();

        Sanctum::actingAs(User::factory()->create(), ['bookmark:write']);

        $this->postJson('/api/v1/bookmarks/capture', [
            'url' => 'https://example.com/private-article',
            'title' => 'The captured article title',
            'html' => '<html><head><title>Short page</title></head><body>Too short.</body></html>',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('html');

        $this->assertDatabaseCount('objects', 0);
        Queue::assertNothingPushed();
    }

    /** @return array{url: string, title: string, html: string} */
    private function payload(): array
    {
        return [
            'url' => 'https://example.com/private-article',
            'title' => 'The captured article title',
            'html' => <<<'HTML'
                <!doctype html>
                <html>
                    <head>
                        <title>The captured article title</title>
                        <meta name="author" content="Alex Example">
                    </head>
                    <body>
                        <article>
                            <h1>The captured article title</h1>
                            <p>This article is visible because it came from an authenticated browser session. It contains enough meaningful prose for Spark's Readability extraction and validation to accept it.</p>
                            <p>The second paragraph makes the captured document representative of the long-form pages that this extension is intended to archive.</p>
                        </article>
                    </body>
                </html>
                HTML,
        ];
    }
}
