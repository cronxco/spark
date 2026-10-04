<?php

namespace App\Mcp\Tools;

use App\Integrations\DailyCheckin\DailyCheckinPlugin;
use App\Mcp\Concerns\RequiresSparkAbility;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Name('get-check-ins')]
#[IsIdempotent]
#[IsReadOnly]
class GetCheckInsTool extends Tool
{
    use RequiresSparkAbility;

    protected string $description = 'Return the morning and afternoon daily check-in records for a date, including completion and recorded energy values.';

    public function handle(Request $request): Response
    {
        if ($error = $this->requireAbility($request, 'insights:read')) {
            return $error;
        }

        $input = strtolower(trim((string) $request->get('date', 'today')));
        try {
            $date = match ($input) {
                'today' => Carbon::today(),
                'yesterday' => Carbon::yesterday(),
                default => Carbon::createFromFormat('Y-m-d', $input),
            };
        } catch (Throwable) {
            return Response::error('Invalid date. Use YYYY-MM-DD, today, or yesterday.');
        }

        if ($date->format('Y-m-d') !== $input && ! in_array($input, ['today', 'yesterday'], true)) {
            return Response::error('Invalid date. Use YYYY-MM-DD, today, or yesterday.');
        }

        $checkins = (new DailyCheckinPlugin)->getCheckinsForDate($request->user()->id, $date->toDateString());

        return Response::json([
            'date' => $date->toDateString(),
            'morning' => $this->format($checkins['morning']),
            'afternoon' => $this->format($checkins['afternoon']),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()
                ->description('Date to inspect: YYYY-MM-DD, today, or yesterday.')
                ->default('today'),
        ];
    }

    private function format(mixed $event): array
    {
        if (! $event) {
            return ['completed' => false];
        }

        return [
            'completed' => true,
            'event_id' => $event->id,
            'physical' => $event->event_metadata['physical_energy'] ?? null,
            'mental' => $event->event_metadata['mental_energy'] ?? null,
            'combined' => $event->value,
            'notes' => $event->event_metadata['notes'] ?? null,
            'time' => $event->time?->toIso8601String(),
        ];
    }
}
