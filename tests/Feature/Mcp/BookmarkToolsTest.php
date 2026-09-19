<?php

namespace Tests\Feature\Mcp;

use App\Jobs\Data\Fetch\ProcessFetchedContent;
use App\Jobs\Fetch\FetchSingleUrl;
use App\Mcp\Servers\SparkServer;
use App\Mcp\Tools\CaptureBookmarkTool;
use App\Mcp\Tools\CreateBookmarkTool;
use App\Models\EventObject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookmarkToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        config(['fetch.url_safety.allowed_hosts' => ['example.com']]);
    }

    #[Test]
    public function both_tools_require_authentication(): void
    {
        SparkServer::tool(CreateBookmarkTool::class, [
            'url' => 'https://example.com/article',
        ])->assertHasErrors(['Authentication required.']);

        SparkServer::tool(CaptureBookmarkTool::class, $this->capturePayload())
            ->assertHasErrors(['Authentication required.']);
    }

    #[Test]
    public function both_tools_require_the_bookmark_write_capability(): void
    {
        $server = $this->actingAsWithAbilities(['data:write']);

        $server->tool(CreateBookmarkTool::class, [
            'url' => 'https://example.com/article',
        ])->assertHasErrors(['Token lacks required capability: bookmark:write.']);

        $server->tool(CaptureBookmarkTool::class, $this->capturePayload())
            ->assertHasErrors(['Token lacks required capability: bookmark:write.']);
    }

    #[Test]
    public function create_bookmark_saves_a_url_and_queues_the_normal_fetch(): void
    {
        Queue::fake();

        $response = SparkServer::actingAs($this->user)->tool(CreateBookmarkTool::class, [
            'url' => 'https://example.com/article',
        ]);

        $response->assertOk();
        $response->assertSee('queued');
        $response->assertSee('https://example.com/article');

        $this->assertDatabaseHas('objects', [
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'url' => 'https://example.com/article',
        ]);
        Queue::assertPushed(FetchSingleUrl::class);
    }

    #[Test]
    public function create_bookmark_deduplicates_an_existing_url(): void
    {
        Queue::fake();

        SparkServer::actingAs($this->user)->tool(CreateBookmarkTool::class, [
            'url' => 'https://example.com/article',
        ])->assertOk();

        Queue::fake();

        $response = SparkServer::actingAs($this->user)->tool(CreateBookmarkTool::class, [
            'url' => 'https://example.com/article',
        ]);

        $response->assertOk();
        $response->assertSee('already_exists');
        $this->assertSame(1, EventObject::where('url', 'https://example.com/article')->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function capture_bookmark_archives_supplied_html_without_refetching(): void
    {
        Queue::fake();

        $response = SparkServer::actingAs($this->user)->tool(
            CaptureBookmarkTool::class,
            $this->capturePayload(),
        );

        $response->assertOk();
        $response->assertSee('captured');
        $response->assertSee('Agent supplied article');

        $bookmark = EventObject::query()
            ->where('user_id', $this->user->id)
            ->where('url', 'https://example.com/agent-article')
            ->firstOrFail();

        $this->assertSame('mcp', $bookmark->metadata['subscription_source']);
        $this->assertSame('agent_supplied_html', $bookmark->metadata['last_capture_method']);
        Queue::assertNotPushed(FetchSingleUrl::class);
        Queue::assertPushed(ProcessFetchedContent::class, function (ProcessFetchedContent $job) use ($bookmark): bool {
            return $job->webpage->is($bookmark)
                && str_contains($job->extracted['text_content'], 'calling agent already has access');
        });
    }

    #[Test]
    public function capture_bookmark_rejects_content_that_cannot_be_extracted(): void
    {
        Queue::fake();

        $response = SparkServer::actingAs($this->user)->tool(CaptureBookmarkTool::class, [
            'url' => 'https://example.com/short',
            'html' => '<html><body>Too short.</body></html>',
        ]);

        $response->assertHasErrors(['Spark could not extract readable content']);
        $this->assertDatabaseCount('objects', 0);
        Queue::assertNothingPushed();
    }

    /** @return array{url: string, title: string, html: string} */
    private function capturePayload(): array
    {
        return [
            'url' => 'https://example.com/agent-article',
            'title' => 'Agent supplied article',
            'html' => <<<'HTML'
                <!doctype html>
                <html>
                    <head><title>Agent supplied article</title></head>
                    <body>
                        <article>
                            <h1>Agent supplied article</h1>
                            <p>This is the complete article content because the calling agent already has access to the page. Spark should extract and archive this prose without fetching the URL again.</p>
                            <p>A second paragraph ensures this resembles the long-form material the bookmark capture pipeline is designed to process and enrich.</p>
                        </article>
                    </body>
                </html>
                HTML,
        ];
    }

    /** @param array<int, string> $abilities */
    private function actingAsWithAbilities(array $abilities): PendingTestResponse
    {
        $token = $this->user->createToken('MCP bookmark test token', $abilities)->accessToken;
        $this->user->withAccessToken($token);

        return SparkServer::actingAs($this->user);
    }
}
