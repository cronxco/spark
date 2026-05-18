<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Mobile\HealthDashboardService;
use App\Services\Mobile\HealthSampleService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HealthController extends Controller
{
    public function __construct(
        protected HealthSampleService $samples,
        protected HealthDashboardService $dashboard,
    ) {}

    /**
     * GET /api/v1/mobile/health/dashboard
     */
    public function dashboard(Request $request): JsonResponse
    {
        $rawDate = $request->query('date');
        if (is_array($rawDate)) {
            return response()->json(['message' => 'Invalid date.'], 422);
        }

        $date = $this->resolveDate($rawDate);
        if ($date === null) {
            return response()->json(['message' => 'Invalid date.'], 422);
        }

        $range = $request->query('range', '7d');
        if (! is_string($range) || ! in_array($range, ['7d', '30d', '90d'], true)) {
            return response()->json(['message' => 'Invalid range.'], 422);
        }

        return response()
            ->json($this->dashboard->dashboard($request->user(), $date, $range))
            ->header('Last-Modified', $date->copy()->endOfDay()->min(Carbon::now())->toRfc7231String());
    }

    /**
     * POST /api/v1/mobile/health/samples
     *
     * Accepts a batch of HealthKit samples from the iOS client and routes them
     * into the existing Apple Health processing jobs. Response is a
     * per-sample status array so the client can retry/ignore at the
     * individual sample level.
     */
    public function samples(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'samples' => ['required', 'array', 'min:1', 'max:500'],
            'samples.*.external_id' => ['required', 'string', 'max:100'],
            'samples.*.type' => ['required', 'string', 'max:100'],
            'samples.*.start' => ['required', 'date'],
            'samples.*.end' => ['nullable', 'date'],
            'samples.*.value' => ['nullable', 'numeric'],
            'samples.*.unit' => ['nullable', 'string', 'max:40'],
            'samples.*.source' => ['nullable', 'string', 'max:100'],
            'samples.*.metadata' => ['nullable', 'array'],
        ]);

        $results = $this->samples->ingest($request->user(), $validated['samples']);

        return response()->json(['results' => $results]);
    }

    protected function resolveDate(?string $input): ?Carbon
    {
        if ($input === null || $input === '') {
            return Carbon::today();
        }

        $input = strtolower(trim($input));

        return match ($input) {
            'today' => Carbon::today(),
            'yesterday' => Carbon::yesterday(),
            'tomorrow' => Carbon::tomorrow(),
            default => $this->parseIso($input),
        };
    }

    protected function parseIso(string $input): ?Carbon
    {
        try {
            $date = Carbon::createFromFormat('Y-m-d', $input);
        } catch (Exception) {
            return null;
        }

        if ($date === false || $date->format('Y-m-d') !== $input) {
            return null;
        }

        return $date->startOfDay();
    }
}
