<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\DayContextResource;
use App\Mcp\Tools\AcknowledgeAnomalyTool;
use App\Mcp\Tools\GetBaselinesTool;
use App\Mcp\Tools\GetBlockTool;
use App\Mcp\Tools\GetDayContextTool;
use App\Mcp\Tools\GetDaySummaryTool;
use App\Mcp\Tools\GetEventsByFilterTool;
use App\Mcp\Tools\GetEventTool;
use App\Mcp\Tools\GetMetricTrendTool;
use App\Mcp\Tools\GetObjectTool;
use App\Mcp\Tools\GetServiceStatusTool;
use App\Mcp\Tools\SearchBlocksTool;
use App\Mcp\Tools\SearchEventsTool;
use App\Mcp\Tools\SearchObjectsTool;
use App\Mcp\Tools\TriggerIntegrationUpdateTool;
use App\Mcp\Tracing\McpSpan;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;

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

        ### Briefing & Summary (start here)
        - `get-day-summary`: **Preferred for daily briefings.** Compact, pre-aggregated summary by domain (health, activity, money, media, knowledge) with baseline comparisons and anomaly detection. Supports multiple dates.
        - `get-service-status`: Check sync coverage and data freshness for all services on a date.

        ### Metrics & Trends
        - `get-metric-trend`: Daily metric values over a date range with baseline comparison, trend direction, and anomaly streaks. Use dot-notation identifiers (e.g. "oura.sleep_score").
        - `get-baselines`: Retrieve baseline statistics (mean, stddev, bounds) for metrics. Omit metrics param to discover all available.
        - `acknowledge-anomaly`: Mark an anomaly as acknowledged with an optional note and suppression period.

        ### Precise Filtering
        - `get-events-by-filter`: Filter events by service, action, and date range. Use for exact queries like "all Monzo transactions this week".

        ### Search & Detail
        - `get-day-context`: Full raw event context for a date (large response — prefer get-day-summary).
        - `search-events`: Search events by semantic or keyword query.
        - `search-blocks`: Search blocks by semantic or keyword query.
        - `search-objects`: Search objects/entities by semantic or keyword query.
        - `get-event`: Get full details for a specific event by ID.
        - `get-object`: Get full details for a specific object by ID.
        - `get-block`: Get full details for a specific block by ID.

        ## Actions
        - `trigger-integration-update`: Trigger an immediate on-demand fetch for a specific integration or all instances of a service (e.g. `service: "oura"`). Does not affect the scheduled pull cycle.

        ## Workflow Tips
        - Start with `get-day-summary` for daily briefings — it replaces multiple get-day-context + parsing calls.
        - Use `get-baselines` to discover available metrics, then `get-metric-trend` for analysis.
        - Use `get-events-by-filter` for precise queries that semantic search can't handle well.
        - Check `get-service-status` when data seems incomplete.
        - Domains: health, money, media, knowledge, online.

        ## Authentication
        All requests require authentication via Sanctum bearer token.
    MARKDOWN;

    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        GetDaySummaryTool::class,
        GetDayContextTool::class,
        GetMetricTrendTool::class,
        GetEventsByFilterTool::class,
        GetServiceStatusTool::class,
        GetBaselinesTool::class,
        AcknowledgeAnomalyTool::class,
        SearchEventsTool::class,
        SearchBlocksTool::class,
        SearchObjectsTool::class,
        GetEventTool::class,
        GetObjectTool::class,
        GetBlockTool::class,
        TriggerIntegrationUpdateTool::class,
    ];

    /**
     * The resources registered with this MCP server.
     *
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        DayContextResource::class,
    ];

    /**
     * The prompts registered with this MCP server.
     *
     * @var array<int, class-string<Prompt>>
     */
    protected array $prompts = [
        //
    ];

    /**
     * Handle incoming JSON-RPC messages with Sentry tracing.
     */
    protected function handleMessage(JsonRpcRequest $request, ServerContext $context): void
    {
        // Handle tool calls with tracing
        if ($request->method === 'tools/call') {
            $toolName = $request->params['name'] ?? 'unknown';
            $arguments = $request->params['arguments'] ?? [];

            McpSpan::toolCall(
                $toolName,
                $this->transport,
                $request->id,
                $arguments,
                fn () => parent::handleMessage($request, $context)
            );

            return;
        }

        // Handle resource reads with tracing
        if ($request->method === 'resources/read') {
            $resourceUri = $request->params['uri'] ?? 'unknown';

            McpSpan::resourceRead(
                $resourceUri,
                $this->transport,
                $request->id,
                fn () => parent::handleMessage($request, $context)
            );

            return;
        }

        // Handle all other methods without tracing
        parent::handleMessage($request, $context);
    }

    /**
     * Handle initialization messages with Sentry tracing.
     */
    protected function handleInitializeMessage(JsonRpcRequest $request, ServerContext $context): void
    {
        $clientInfo = $request->params['clientInfo'] ?? [];

        McpSpan::initialize(
            $this->transport,
            $request->id,
            $clientInfo,
            function () use ($request, $context, $clientInfo): void {
                parent::handleInitializeMessage($request, $context);

                // Set session-level metadata after successful initialization
                McpSpan::setSessionMetadata(
                    $clientInfo,
                    $this->name,
                    $this->version,
                    '2025-11-25'
                );
            }
        );
    }
}
