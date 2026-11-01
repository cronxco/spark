<?php

namespace App\Mcp\Tools;

use App\Exceptions\UnsafeUrlException;
use App\Integrations\Fetch\PlaywrightFetchClient;
use App\Mcp\Concerns\RequiresSparkAbility;
use App\Services\Fetch\UrlSafetyValidator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('fetch-webpage-html')]
class FetchWebpageHtmlTool extends Tool
{
    use RequiresSparkAbility;

    protected string $description = <<<'MARKDOWN'
        Render a website in Spark's Playwright browser and return its current raw HTML.

        Uses the logged-in browser session when the caller has a Fetch integration and
        default browser context reuse is enabled. Otherwise, uses saved Fetch cookies in
        an isolated context. Refreshed target-domain cookies are saved to the caller's Fetch store.
        The response is limited to 1 MB of HTML and says explicitly if it was truncated.
    MARKDOWN;

    public function handle(Request $request): Response
    {
        if ($error = $this->requireAbility($request, 'web:fetch')) {
            return $error;
        }
        $user = $request->user();

        if (! $user) {
            return Response::error('Authentication required.');
        }

        $url = $request->get('url');
        if (! is_string($url) || blank($url)) {
            return Response::error('URL is required.');
        }

        try {
            app(UrlSafetyValidator::class)->validate($url);
        } catch (UnsafeUrlException $e) {
            return Response::error($e->getMessage());
        }

        $fetchGroup = $user->integrationGroups()
            ->where('service', 'fetch')
            ->first();

        $result = app(PlaywrightFetchClient::class)->fetchForMcp($url, $fetchGroup);

        if (! $result['success']) {
            return Response::error('Playwright fetch failed: ' . $result['error']);
        }

        return Response::text(json_encode([
            'requested_url' => $url,
            'final_url' => $result['url'],
            'title' => $result['title'],
            'status' => $result['status'],
            'html' => $result['html'],
            'html_bytes' => $result['html_bytes'],
            'returned_html_bytes' => $result['returned_html_bytes'],
            'html_truncated' => $result['html_truncated'],
            'used_saved_cookies' => $result['used_saved_cookies'],
            'cookie_store_updated' => $result['cookie_store_updated'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()
                ->description('Public HTTP or HTTPS URL to render in Playwright.'),
        ];
    }
}
