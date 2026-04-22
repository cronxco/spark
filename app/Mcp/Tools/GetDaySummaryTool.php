<?php

namespace App\Mcp\Tools;

use App\Mcp\Helpers\DateParser;
use App\Services\DaySummaryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsIdempotent]
#[IsReadOnly]
class GetDaySummaryTool extends Tool
{
    use DateParser;

    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Get a compact, pre-aggregated summary for one or more dates.
        Returns structured domain sections (health, activity, money, media, knowledge)
        with baseline comparisons and anomaly detection.
        Much more compact than get-day-context — use this for daily briefings.
    MARKDOWN;

    public function __construct(
        protected DaySummaryService $summaryService
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $request->user();

        if (! $user) {
            return Response::error('Authentication required.');
        }

        // Parse dates input (supports array or single string)
        $datesInput = $request->get('dates', ['today']);
        if (is_string($datesInput)) {
            $datesInput = [$datesInput];
        }

        $dates = $this->parseDates($datesInput);

        if (empty($dates)) {
            return Response::error('No valid dates provided. Use ISO format (YYYY-MM-DD) or relative: "today", "yesterday", "tomorrow".');
        }

        // Parse domains filter
        $domains = $request->get('domains');
        if ($domains && ! is_array($domains)) {
            $domains = [$domains];
        }

        // Generate summary for each date
        $summaries = [];
        foreach ($dates as $date) {
            $summaries[] = $this->summaryService->generateSummary($user, $date, $domains);
        }

        // If single date, return directly; if multiple, wrap in array
        $result = count($summaries) === 1 ? $summaries[0] : $summaries;

        return Response::text(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'dates' => $schema->array()
                ->items($schema->string())
                ->description('Array of dates to summarize. ISO format (YYYY-MM-DD) or relative: "today", "yesterday", "tomorrow". Defaults to ["today"].')
                ->default(['today']),

            'domains' => $schema->array()
                ->items($schema->string()->enum(['health', 'activity', 'money', 'media', 'knowledge']))
                ->description('Filter by domains. Omit to include all domains.'),
        ];
    }
}
