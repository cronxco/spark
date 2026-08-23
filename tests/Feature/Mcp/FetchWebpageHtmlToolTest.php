<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SparkServer;
use App\Mcp\Tools\FetchWebpageHtmlTool;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FetchWebpageHtmlToolTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        config([
            'fetch.url_safety.allowed_hosts' => ['example.com'],
            'services.playwright.worker_url' => 'http://playwright-worker:3000',
            'services.playwright.mcp_max_html_bytes' => 1048576,
        ]);
    }

    #[Test]
    public function it_requires_authentication(): void
    {
        SparkServer::tool(FetchWebpageHtmlTool::class, ['url' => 'https://example.com'])
            ->assertHasErrors(['Authentication required.']);
    }

    #[Test]
    public function it_returns_rendered_html_from_the_worker(): void
    {
        $this->fakeWorker();

        $response = SparkServer::actingAs($this->user)->tool(FetchWebpageHtmlTool::class, [
            'url' => 'https://example.com/article',
        ]);

        $response->assertOk();
        $response->assertSee('"final_url": "https://example.com/article"');
        $response->assertSee('<html><body>Rendered</body></html>');
        $response->assertSee('"html_truncated": false');
        $response->assertSee('"used_saved_cookies": false');

        Http::assertSent(function (HttpRequest $request): bool {
            $data = $request->data();

            return $request->url() === 'http://playwright-worker:3000/fetch'
                && $data['screenshot'] === false
                && $data['usePersistence'] === false
                && $data['useDefaultContext'] === false
                && $data['maxHtmlBytes'] === 1048576;
        });
    }

    #[Test]
    public function it_uses_and_persists_matching_saved_cookies(): void
    {
        $group = IntegrationGroup::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'fetch',
            'auth_metadata' => [
                'domains' => [
                    'example.com' => [
                        'cookies' => [
                            ['name' => 'session', 'value' => 'old', 'domain' => '.example.com', 'path' => '/'],
                        ],
                    ],
                ],
            ],
        ]);
        $this->fakeWorker([
            ['name' => 'session', 'value' => 'new', 'domain' => '.example.com', 'path' => '/', 'secure' => true, 'httpOnly' => true],
        ]);

        $response = SparkServer::actingAs($this->user)->tool(FetchWebpageHtmlTool::class, [
            'url' => 'https://example.com/account',
        ]);

        $response->assertOk();
        $response->assertSee('"used_saved_cookies": true');
        $response->assertSee('"cookie_store_updated": true');

        $group->refresh();
        $this->assertSame('new', $group->auth_metadata['domains']['example.com']['cookies'][0]['value']);
    }

    #[Test]
    public function it_rejects_unsafe_urls_without_contacting_the_worker(): void
    {
        Http::fake();

        $response = SparkServer::actingAs($this->user)->tool(FetchWebpageHtmlTool::class, [
            'url' => 'http://127.0.0.1/admin',
        ]);

        $response->assertHasErrors(['Unsafe URL rejected']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_returns_worker_unavailable_errors_without_http_fallback(): void
    {
        Http::fake([
            'http://playwright-worker:3000/health' => Http::response(['status' => 'degraded', 'connected' => false], 503),
        ]);

        $response = SparkServer::actingAs($this->user)->tool(FetchWebpageHtmlTool::class, [
            'url' => 'https://example.com',
        ]);

        $response->assertHasErrors(['Playwright worker is not available']);
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_exposes_worker_html_truncation_metadata(): void
    {
        $this->fakeWorker([], ['htmlBytes' => 2000000, 'returnedHtmlBytes' => 1048576, 'htmlTruncated' => true]);

        $response = SparkServer::actingAs($this->user)->tool(FetchWebpageHtmlTool::class, [
            'url' => 'https://example.com/large',
        ]);

        $response->assertOk();
        $response->assertSee('"html_bytes": 2000000');
        $response->assertSee('"returned_html_bytes": 1048576');
        $response->assertSee('"html_truncated": true');
    }

    /** @param  list<array<string, mixed>>  $cookies */
    private function fakeWorker(array $cookies = [], array $meta = []): void
    {
        Http::fake([
            'http://playwright-worker:3000/health' => Http::response(['status' => 'ok', 'connected' => true]),
            'http://playwright-worker:3000/fetch' => Http::response([
                'success' => true,
                'html' => '<html><body>Rendered</body></html>',
                'title' => 'Rendered page',
                'url' => 'https://example.com/article',
                'cookies' => $cookies,
                'meta' => array_merge([
                    'status' => 200,
                    'htmlBytes' => 34,
                    'returnedHtmlBytes' => 34,
                    'htmlTruncated' => false,
                ], $meta),
            ]),
        ]);
    }
}
