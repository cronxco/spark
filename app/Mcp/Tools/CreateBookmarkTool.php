<?php

namespace App\Mcp\Tools;

use App\Exceptions\UnsafeUrlException;
use App\Mcp\Concerns\RequiresSparkAbility;
use App\Services\Fetch\BookmarkUrlService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create-bookmark')]
class CreateBookmarkTool extends Tool
{
    use RequiresSparkAbility;

    protected string $description = <<<'MARKDOWN'
        Save a public HTTP or HTTPS URL as a Spark bookmark.

        Spark deduplicates bookmarks by URL. New bookmarks are queued for normal webpage
        fetching and enrichment when the user's Fetch integration is available.
    MARKDOWN;

    public function __construct(protected BookmarkUrlService $bookmarks) {}

    public function handle(Request $request): Response
    {
        if ($error = $this->requireAbility($request, 'bookmark:write')) {
            return $error;
        }

        $url = $request->get('url');
        if (! is_string($url) || blank($url) || strlen($url) > 2048) {
            return Response::error('url is required and must be no more than 2,048 characters.');
        }

        try {
            $result = $this->bookmarks->bookmark($request->user(), $url);
        } catch (UnsafeUrlException $exception) {
            return Response::error($exception->getMessage());
        }

        $bookmark = $result['bookmark'];

        return Response::json([
            'state' => $result['state'],
            'bookmark' => [
                'id' => $bookmark->id,
                'url' => $bookmark->url,
                'title' => $bookmark->title,
            ],
            'job_dispatched' => $result['job_dispatched'],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()
                ->description('Public HTTP or HTTPS URL to bookmark (maximum 2,048 characters).')
                ->required(),
        ];
    }
}
