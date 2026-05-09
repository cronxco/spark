<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Compact\CompactEventResource;
use App\Integrations\DailyCheckin\DailyCheckinPlugin;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CheckInsController extends Controller
{
    /**
     * GET /api/v1/mobile/check-ins?date=YYYY-MM-DD
     *
     * Returns morning and afternoon completion status for a given date.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $checkins = (new DailyCheckinPlugin)->getCheckinsForDate(
            $request->user()->id,
            $validated['date'],
        );

        return response()->json([
            'date' => $validated['date'],
            'morning' => $this->formatPeriodStatus($checkins['morning'], $request),
            'afternoon' => $this->formatPeriodStatus($checkins['afternoon'], $request),
        ]);
    }

    /**
     * GET /api/v1/mobile/check-ins/history?from=YYYY-MM-DD&to=YYYY-MM-DD
     *
     * Returns a lightweight day-by-day summary for a date range (max 90 days).
     */
    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $from = Carbon::parse($validated['from']);
        $to = Carbon::parse($validated['to']);

        if ($from->diffInDays($to) > 90) {
            return response()->json(['message' => 'Date range may not exceed 90 days.'], 422);
        }

        $sourceIds = [];
        foreach (CarbonPeriod::create($from, $to) as $date) {
            $sourceIds[] = 'daily_checkin_morning_' . $date->toDateString();
            $sourceIds[] = 'daily_checkin_afternoon_' . $date->toDateString();
        }

        $events = Event::whereHas('integration', function ($q) use ($request) {
            $q->where('user_id', $request->user()->id);
        })
            ->where('service', 'daily_checkin')
            ->whereIn('source_id', $sourceIds)
            ->get()
            ->groupBy(fn (Event $e) => $e->event_metadata['date'] ?? Carbon::parse($e->time)->toDateString());

        $days = [];
        foreach (CarbonPeriod::create($from, $to) as $date) {
            $dateString = $date->toDateString();
            $dayEvents = $events->get($dateString, collect());

            $morning = $dayEvents->firstWhere('action', 'had_morning_checkin');
            $afternoon = $dayEvents->firstWhere('action', 'had_afternoon_checkin');

            $days[] = [
                'date' => $dateString,
                'morning' => $morning
                    ? [
                        'completed' => true,
                        'physical' => $morning->event_metadata['physical_energy'] ?? null,
                        'mental' => $morning->event_metadata['mental_energy'] ?? null,
                        'combined' => $morning->value,
                        'notes' => $morning->event_metadata['notes'] ?? null,
                        'event_id' => $morning->id,
                    ]
                    : ['completed' => false],
                'afternoon' => $afternoon
                    ? [
                        'completed' => true,
                        'physical' => $afternoon->event_metadata['physical_energy'] ?? null,
                        'mental' => $afternoon->event_metadata['mental_energy'] ?? null,
                        'combined' => $afternoon->value,
                        'notes' => $afternoon->event_metadata['notes'] ?? null,
                        'event_id' => $afternoon->id,
                    ]
                    : ['completed' => false],
            ];
        }

        return response()->json([
            'from' => $validated['from'],
            'to' => $validated['to'],
            'days' => $days,
        ]);
    }

    /**
     * POST /api/v1/mobile/check-ins
     *
     * Forwards to DailyCheckinPlugin::createCheckinEvent which owns all the
     * validation, event/object wiring, and location linking.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'string', 'in:morning,afternoon'],
            'physical' => ['required', 'integer', 'min:1', 'max:5'],
            'mental' => ['required', 'integer', 'min:1', 'max:5'],
            'date' => ['required', 'date_format:Y-m-d'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $integration = $this->resolveIntegration($request);

        $event = (new DailyCheckinPlugin)->createCheckinEvent(
            $integration,
            $validated['period'],
            $validated['physical'],
            $validated['mental'],
            $validated['date'],
            $validated['latitude'] ?? null,
            $validated['longitude'] ?? null,
            $validated['address'] ?? null,
            $validated['notes'] ?? null,
        );

        return response()->json(
            (new CompactEventResource($event))->resolve($request),
            201,
        );
    }

    protected function resolveIntegration(Request $request): Integration
    {
        $user = $request->user();

        $group = IntegrationGroup::firstOrCreate(
            ['user_id' => $user->id, 'service' => 'daily_checkin'],
            [
                'account_id' => Str::uuid()->toString(),
                'access_token' => 'mobile',
            ],
        );

        return Integration::firstOrCreate(
            ['user_id' => $user->id, 'service' => 'daily_checkin'],
            [
                'integration_group_id' => $group->id,
                'name' => 'Daily Check-in',
                'account_id' => $group->account_id,
            ],
        );
    }

    /**
     * @return array{completed: bool, event: array<string, mixed>|null}
     */
    private function formatPeriodStatus(?Event $event, Request $request): array
    {
        if ($event === null) {
            return ['completed' => false, 'event' => null];
        }

        return [
            'completed' => true,
            'event' => (new CompactEventResource($event))->resolve($request),
        ];
    }
}
