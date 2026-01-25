<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\DayContextResource;
use App\Mcp\Tools\GetBlockTool;
use App\Mcp\Tools\GetDayContextTool;
use App\Mcp\Tools\GetEventTool;
use App\Mcp\Tools\GetObjectTool;
use App\Mcp\Tools\SearchBlocksTool;
use App\Mcp\Tools\SearchEventsTool;
use App\Mcp\Tools\SearchObjectsTool;
use Laravel\Mcp\Server;

class SparkServer extends Server
{
    /**
     * The MCP server's name.
     */
    protected string $name = 'Spark';

    /**
     * The MCP server's version.
     */
    protected string $version = '1.0.0';

    /**
     * The MCP server's instructions for the LLM.
     */
    protected string $instructions = <<<'MARKDOWN'
        Spark is a personal data integration platform that aggregates data from multiple services (health, finance, media, etc.) into a unified event-based system.

        ## Data Model
        - **Events**: Timestamped activities (transactions, workouts, media plays)
        - **Objects**: Entities (users, accounts, tracks, merchants)
        - **Blocks**: Data units attached to events (summaries, metrics, content)

        ## Available Tools
        - `get-day-context`: Get structured context for a specific date
        - `search-events`: Search events by semantic or keyword
        - `search-blocks`: Search blocks by semantic or keyword
        - `search-objects`: Search objects/entities by semantic or keyword
        - `get-event`: Get full details for a specific event
        - `get-object`: Get full details for a specific object
        - `get-block`: Get full details for a specific block

        ## Search Tips
        - Use semantic search (default) for natural language queries
        - Use keyword search for exact matches
        - Filter by service, domain, date range for more specific results
        - Domains: health, money, media, knowledge, online

        ## Authentication
        All requests require authentication via Sanctum bearer token.
    MARKDOWN;

    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        GetDayContextTool::class,
        SearchEventsTool::class,
        SearchBlocksTool::class,
        SearchObjectsTool::class,
        GetEventTool::class,
        GetObjectTool::class,
        GetBlockTool::class,
    ];

    /**
     * The resources registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Resource>>
     */
    protected array $resources = [
        DayContextResource::class,
    ];

    /**
     * The prompts registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Prompt>>
     */
    protected array $prompts = [
        //
    ];
}
