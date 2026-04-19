<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\DaySummaryService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BriefingController extends Controller
{
    public function __construct(protected DaySummaryService $summaryService) {}

    /**
     * GET /api/v1/mobile/briefing/today
     *
     * Accepts `date` (YYYY-MM-DD or relative "today"/"yesterday") — defaults to today.
     * Optional `domains` (comma-separated) narrows the summary.
     */
    public function today(Request $request): JsonResponse
    {
        $date = $this->resolveDate($request->query('date'));

        if ($date === null) {
            return response()->json(['message' => 'Invalid date.'], 422);
        }

        $domains = null;
        if ($request->filled('domains')) {
            $domains = array_values(array_filter(array_map('trim', explode(',', (string) $request->query('domains')))));
            if ($domains === []) {
                $domains = null;
            }
        }

        $summary = $this->summaryService->generateSummary($request->user(), $date, $domains);

        return response()
            ->json($summary)
            ->header('Last-Modified', $date->copy()->endOfDay()->toRfc7231String());
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
            return Carbon::parse($input)->startOfDay();
        } catch (Exception) {
            return null;
        }
    }
}
