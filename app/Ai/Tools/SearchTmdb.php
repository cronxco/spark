<?php

namespace App\Ai\Tools;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Lets the model retry a TMDB search with a cleaned-up title. The search
 * itself belongs to the caller, which also keeps the results it hands out.
 */
class SearchTmdb implements Tool
{
    /** @param Closure(string): array<int, mixed> $search Query => candidate summaries */
    public function __construct(private Closure $search) {}

    public function name(): string
    {
        return 'search_tmdb';
    }

    public function description(): string
    {
        return 'Search TMDB (The Movie Database) for movies and TV shows by title. Use this to retry with a cleaned-up query if the initial results do not contain a confident match.';
    }

    public function handle(Request $request): string
    {
        return (string) json_encode(['results' => ($this->search)((string) ($request['query'] ?? ''))]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The search query to send to TMDB.')->required(),
        ];
    }
}
