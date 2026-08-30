<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Api\DayContextService;
use App\Services\Api\ServiceStatusService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContextController extends Controller
{
    public function __construct(private DayContextService $context, private ServiceStatusService $status) {}

    public function day(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'domains' => ['nullable', 'array', 'max:10'],
            'domains.*' => ['string', 'max:100'],
        ]);
        $date = Carbon::createFromFormat('Y-m-d', $data['date'] ?? now()->toDateString());

        return response()->json($this->context->forDay($request->user(), $date, $data['domains'] ?? null));
    }

    public function status(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = Carbon::createFromFormat('Y-m-d', $data['date'] ?? now()->toDateString());

        return response()->json($this->status->forDay($request->user(), $date));
    }
}
