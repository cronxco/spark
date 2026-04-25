<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Mobile\HealthSampleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HealthController extends Controller
{
    public function __construct(protected HealthSampleService $samples) {}

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
}
