<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Api\DayContextService;
use App\Services\Api\ServiceStatusService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MR-18: both endpoints here are superseded in practice by
 * `GET /briefing/today` — `day()` by the briefing's `sections`, `status()` by
 * its `sync_status` (now carrying the same `stale`/`as_of` judgement, see
 * MR-1). No mobile client surface calls either. Kept live and unchanged for
 * any existing caller, but marked for removal.
 */
class ContextController extends Controller
{
    /** ISO date this deprecation was announced; six months out is the removal target. */
    private const DEPRECATED_SINCE = '2026-09-20';

    public function __construct(private DayContextService $context, private ServiceStatusService $status) {}

    public function day(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'domains' => ['nullable', 'array', 'max:10'],
            'domains.*' => ['string', 'max:100'],
        ]);
        $timezone = $request->user()->getTimezone();
        $date = Carbon::createFromFormat('Y-m-d', $data['date'] ?? now($timezone)->toDateString(), $timezone);

        return $this->deprecated(
            response()->json($this->context->forDay($request->user(), $date, $data['domains'] ?? null))
        );
    }

    public function status(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $timezone = $request->user()->getTimezone();
        $date = Carbon::createFromFormat('Y-m-d', $data['date'] ?? now($timezone)->toDateString(), $timezone);

        return $this->deprecated(
            response()->json($this->status->forDay($request->user(), $date))
        );
    }

    private function deprecated(JsonResponse $response): JsonResponse
    {
        return $response
            ->header('Deprecation', 'true')
            ->header('Sunset', Carbon::parse(self::DEPRECATED_SINCE)->addMonths(6)->toRfc7231String())
            ->header('Link', '</api/v1/mobile/briefing/today>; rel="successor-version"');
    }
}
