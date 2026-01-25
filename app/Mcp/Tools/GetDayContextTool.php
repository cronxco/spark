<?php

namespace App\Mcp\Tools;

use App\Models\Integration;
use App\Services\AssistantContextService;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsIdempotent]
#[IsReadOnly]
class GetDayContextTool extends Tool
{
    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Retrieve structured day context including events, metrics, and relationships for a specific date.
        Returns grouped events by service/action/hour, service breakdown, and relationship data.
        Supports filtering by domains (health, money, media, knowledge, online).
    MARKDOWN;

    public function __construct(
        protected AssistantContextService $contextService
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

        // Parse date input
        $dateInput = $request->get('date', 'today');
        $baseDate = $this->parseDate($dateInput);

        if (! $baseDate) {
            return Response::error('Invalid date format. Use ISO date (YYYY-MM-DD) or relative: today, yesterday, tomorrow.');
        }

        // Parse domains filter
        $domains = $request->get('domains');
        if ($domains && ! is_array($domains)) {
            $domains = [$domains];
        }

        // Get or create a mock integration for context generation
        // The AssistantContextService requires an Integration for timeframe config
        $assistantIntegration = Integration::query()
            ->where('user_id', $user->id)
            ->where('service', 'flint')
            ->first();

        // If no Flint integration, create a mock configuration
        if (! $assistantIntegration) {
            // Create a temporary mock integration object for context generation
            $assistantIntegration = new Integration([
                'user_id' => $user->id,
                'service' => 'flint',
                'name' => 'MCP Context',
                'configuration' => [
                    'today_enabled' => true,
                    'yesterday_enabled' => true,
                    'tomorrow_enabled' => true,
                    'include_relationships' => true,
                    'max_events_per_timeframe' => 200,
                ],
            ]);
        }

        // Determine the timeframe key
        $timeframe = $this->getTimeframeKey($dateInput, $baseDate);

        // Generate context for the specific timeframe
        $context = $this->contextService->generateTimeframeContext(
            $user,
            $timeframe,
            $baseDate,
            $assistantIntegration,
            $domains
        );

        return Response::text(json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()
                ->description('Date to retrieve context for. ISO format (YYYY-MM-DD) or relative: "today", "yesterday", "tomorrow".')
                ->default('today'),

            'domains' => $schema->array()
                ->items($schema->string()->enum(['health', 'money', 'media', 'knowledge', 'online']))
                ->description('Filter by domains. Omit to include all domains.'),
        ];
    }

    /**
     * Parse date input to Carbon instance.
     */
    protected function parseDate(string $input): ?Carbon
    {
        $input = strtolower(trim($input));

        return match ($input) {
            'today' => Carbon::today(),
            'yesterday' => Carbon::yesterday(),
            'tomorrow' => Carbon::tomorrow(),
            default => $this->parseIsoDate($input),
        };
    }

    /**
     * Parse ISO date string.
     */
    protected function parseIsoDate(string $input): ?Carbon
    {
        try {
            return Carbon::parse($input)->startOfDay();
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Get the timeframe key for the AssistantContextService.
     */
    protected function getTimeframeKey(string $input, Carbon $targetDate): string
    {
        $input = strtolower(trim($input));

        if (in_array($input, ['today', 'yesterday', 'tomorrow'])) {
            return $input;
        }

        // For specific dates, determine relative position to today
        $today = Carbon::today();
        $diffDays = $today->diffInDays($targetDate, signed: false);

        if ($targetDate->isSameDay($today)) {
            return 'today';
        }

        if ($targetDate->isSameDay($today->copy()->subDay())) {
            return 'yesterday';
        }

        if ($targetDate->isSameDay($today->copy()->addDay())) {
            return 'tomorrow';
        }

        // For dates further away, use 'today' as the base timeframe
        // but with the provided baseDate
        return 'today';
    }
}
