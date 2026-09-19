<?php

namespace App\Mcp\Tools;

use App\Exceptions\CapturedContentExtractionException;
use App\Exceptions\UnsafeUrlException;
use App\Mcp\Concerns\RequiresSparkAbility;
use App\Services\Fetch\CaptureBookmarkService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('capture-bookmark')]
class CaptureBookmarkTool extends Tool
{
    use RequiresSparkAbility;

    protected string $description = <<<'MARKDOWN'
        Save a webpage as a Spark bookmark using HTML already available to the calling agent.

        Use this when the agent has page content that Spark may not be able to fetch itself,
        such as a logged-in or paywalled page. Spark extracts and enriches the supplied HTML
        without fetching the URL again. Repeated calls for the same URL update the existing
        bookmark rather than creating duplicates.
    MARKDOWN;

    public function __construct(protected CaptureBookmarkService $captures) {}

    public function handle(Request $request): Response
    {
        if ($error = $this->requireAbility($request, 'bookmark:write')) {
            return $error;
        }

        $url = $request->get('url');
        $html = $request->get('html');
        $title = $request->get('title');

        if (! is_string($url) || blank($url) || strlen($url) > 2048) {
            return Response::error('url is required and must be no more than 2,048 characters.');
        }

        if (! is_string($html) || blank($html) || strlen($html) > 5 * 1024 * 1024) {
            return Response::error('html is required and must be no more than 5 MB.');
        }

        if ($title !== null && (! is_string($title) || mb_strlen($title) > 1000)) {
            return Response::error('title must be a string no more than 1,000 characters.');
        }

        try {
            $result = $this->captures->capture(
                $request->user(),
                $url,
                $html,
                $title,
                source: 'mcp',
                captureMethod: 'agent_supplied_html',
            );
        } catch (UnsafeUrlException $exception) {
            return Response::error($exception->getMessage());
        } catch (CapturedContentExtractionException $exception) {
            return Response::error('Spark could not extract readable content: ' . $exception->getMessage());
        }

        $bookmark = $result['bookmark'];

        return Response::json([
            'state' => $result['state'],
            'bookmark' => [
                'id' => $bookmark->id,
                'url' => $bookmark->url,
                'title' => $bookmark->title,
            ],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()
                ->description('Public HTTP or HTTPS canonical URL for the page (maximum 2,048 characters).')
                ->required(),
            'html' => $schema->string()
                ->description('Complete rendered page HTML to extract and archive (maximum 5 MB).')
                ->required(),
            'title' => $schema->string()
                ->description('Optional page title. When present, it overrides the title extracted from the HTML.'),
        ];
    }
}
