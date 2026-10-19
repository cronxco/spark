<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Mobile\StoreLogRequest;
use App\Models\Event;
use App\Services\ManualLog\ManualLogService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class LogController extends Controller
{
    public function __construct(protected ManualLogService $logs) {}

    public function store(StoreLogRequest $request): JsonResponse
    {
        try {
            $result = $this->logs->log($request->user(), $request->validated());
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['title' => [$exception->getMessage()]],
            ], 422);
        }

        if ($result['status'] === 'needs_clarification') {
            return response()->json($result);
        }

        return response()->json([
            'status' => 'logged',
            'events' => array_map(fn (Event $event) => [
                'id' => $event->id,
                'action' => $event->action,
                'title' => $event->target?->title,
                'time' => $event->time?->toIso8601String(),
            ], $result['events']),
            'missing_episodes' => $result['missing_episodes'] ?? [],
        ], $result['created'] ? 201 : 200);
    }
}
