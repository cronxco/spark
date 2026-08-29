<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresSparkAbility;
use App\Mcp\Helpers\DateParser;
use App\Services\Api\ServiceStatusService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsIdempotent]
#[IsReadOnly]
class GetServiceStatusTool extends Tool
{
    use DateParser;
    use RequiresSparkAbility;

    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Check sync status and data coverage for all services on a given date.
        Shows event count, last event time, distinct actions, and coverage notes
        for services with known sync lag (e.g. Apple Health).
    MARKDOWN;

    public function __construct(private ServiceStatusService $status) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        if ($error = $this->requireAbility($request, 'insights:read')) {
            return $error;
        }
        $user = $request->user();

        if (! $user) {
            return Response::error('Authentication required.');
        }

        $dateInput = $request->get('date', 'today');
        $date = $this->parseDate($dateInput);

        if (! $date) {
            return Response::error('Invalid date format. Use ISO date (YYYY-MM-DD) or relative: "today", "yesterday", "tomorrow".');
        }

        return Response::text(json_encode($this->status->forDay($user, $date), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()
                ->description('Date to check. ISO format (YYYY-MM-DD) or relative: "today", "yesterday", "tomorrow". Defaults to "today".')
                ->default('today'),
        ];
    }
}
