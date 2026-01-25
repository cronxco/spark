<?php

namespace App\Mcp\Resources;

use App\Models\Integration;
use App\Services\AssistantContextService;
use Carbon\Carbon;
use Exception;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

class DayContextResource extends Resource implements HasUriTemplate
{
    /**
     * The resource's description.
     */
    protected string $description = <<<'MARKDOWN'
        Structured day context including events, metrics, and relationships for a specific date.
        The date parameter can be an ISO date (YYYY-MM-DD) or relative keywords: today, yesterday, tomorrow.
    MARKDOWN;

    /**
     * The resource's MIME type.
     */
    protected string $mimeType = 'application/json';

    public function __construct(
        protected AssistantContextService $contextService
    ) {}

    /**
     * Get the URI template for this resource.
     */
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('spark://context/day/{date}');
    }

    /**
     * Handle the resource request.
     */
    public function handle(Request $request): Response
    {
        $user = $request->user();

        if (! $user) {
            return Response::error('Authentication required.');
        }

        $dateInput = $request->get('date', 'today');
        $baseDate = $this->parseDate($dateInput);

        if (! $baseDate) {
            return Response::error('Invalid date format. Use ISO date (YYYY-MM-DD) or relative: today, yesterday, tomorrow.');
        }

        // Get or create a mock integration for context generation
        $assistantIntegration = Integration::query()
            ->where('user_id', $user->id)
            ->where('service', 'flint')
            ->where('instance_type', 'assistant')
            ->first();

        if (! $assistantIntegration) {
            $assistantIntegration = new Integration([
                'user_id' => $user->id,
                'service' => 'flint',
                'instance_type' => 'assistant',
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

        $timeframe = $this->getTimeframeKey($dateInput, $baseDate);

        $context = $this->contextService->generateTimeframeContext(
            $user,
            $timeframe,
            $baseDate,
            $assistantIntegration
        );

        return Response::text(json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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

        $today = Carbon::today();

        if ($targetDate->isSameDay($today)) {
            return 'today';
        }

        if ($targetDate->isSameDay($today->copy()->subDay())) {
            return 'yesterday';
        }

        if ($targetDate->isSameDay($today->copy()->addDay())) {
            return 'tomorrow';
        }

        return 'today';
    }
}
