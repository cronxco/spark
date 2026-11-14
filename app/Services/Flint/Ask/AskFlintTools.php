<?php

namespace App\Services\Flint\Ask;

use App\Ai\Tools\AskFlint\DeleteEventTool;
use App\Ai\Tools\AskFlint\LinkRecordsTool;
use App\Ai\Tools\AskFlint\LogEventTool;
use App\Ai\Tools\AskFlint\SetEventNoteTool;
use App\Ai\Tools\AskFlint\TagEventTool;
use App\Ai\Tools\AskFlint\UpdateRecordTool;
use App\Mcp\Tools\GetBaselinesTool;
use App\Mcp\Tools\GetBlockTool;
use App\Mcp\Tools\GetCheckInsTool;
use App\Mcp\Tools\GetDaySummaryTool;
use App\Mcp\Tools\GetEventsByFilterTool;
use App\Mcp\Tools\GetEventTool;
use App\Mcp\Tools\GetFlintNotesTool;
use App\Mcp\Tools\GetLatestFlintDigestTool;
use App\Mcp\Tools\GetMetricTrendTool;
use App\Mcp\Tools\GetMoneyPlanTool;
use App\Mcp\Tools\GetObjectTool;
use App\Mcp\Tools\ListIntegrationsTool;
use App\Mcp\Tools\SearchBlocksTool;
use App\Mcp\Tools\SearchEventsTool;
use App\Mcp\Tools\SearchObjectsTool;
use App\Models\User;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Mcp\Server\Tool as McpTool;

/**
 * The tools Ask Flint holds.
 *
 * Reads are Spark's own MCP tools, run in-process exactly as CronxTools would
 * call them, so they keep their capability checks and their output. The list
 * is an allowlist: nothing that fetches the web, triggers a sync, runs a
 * routine or manages tokens is here, however the MCP server grows. Writes are
 * the Ask Flint write tools, the only ones that leave an Undo receipt.
 */
class AskFlintTools
{
    /** @var array<int, class-string<McpTool>> */
    public const READ_TOOLS = [
        GetDaySummaryTool::class,
        GetMetricTrendTool::class,
        GetBaselinesTool::class,
        GetCheckInsTool::class,
        GetEventsByFilterTool::class,
        SearchEventsTool::class,
        SearchObjectsTool::class,
        SearchBlocksTool::class,
        GetEventTool::class,
        GetObjectTool::class,
        GetBlockTool::class,
        GetMoneyPlanTool::class,
        GetFlintNotesTool::class,
        GetLatestFlintDigestTool::class,
        ListIntegrationsTool::class,
    ];

    /** @var array<int, class-string<Tool>> */
    public const WRITE_TOOLS = [
        UpdateRecordTool::class,
        SetEventNoteTool::class,
        TagEventTool::class,
        LinkRecordsTool::class,
        DeleteEventTool::class,
        LogEventTool::class,
    ];

    /**
     * The names the write tools go by, for reading changes back out of a
     * stored turn.
     *
     * @return array<int, string>
     */
    public static function writeToolNames(): array
    {
        return ['update-record', 'set-event-note', 'tag-event', 'link-records', 'delete-event', 'log-event'];
    }

    /**
     * @param  User  $actor  the user, holding the turn's scoped token
     * @return array<int, Tool>
     */
    public function for(User $actor, AskFlintChangeLog $changes, bool $canWrite): array
    {
        $tools = array_map(
            fn (string $class): Tool => new McpServerTool(app($class)),
            self::READ_TOOLS,
        );

        if (! $canWrite) {
            return $tools;
        }

        return [
            ...$tools,
            ...array_map(
                fn (string $class): Tool => app()->make($class, ['user' => $actor, 'changes' => $changes]),
                self::WRITE_TOOLS,
            ),
        ];
    }
}
