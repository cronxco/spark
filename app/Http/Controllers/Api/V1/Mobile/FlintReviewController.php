<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Flint\FlintReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** The Flint Review queue for the iOS app (decisions E-1 and EX-D4). */
class FlintReviewController extends Controller
{
    public function index(Request $request, FlintReviewService $review): JsonResponse
    {
        $items = $review->items($request->user());

        return response()->json(['data' => $items, 'meta' => ['count' => count($items)]]);
    }

    public function act(Request $request, FlintReviewService $review, string $kind, string $id): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['confirm', 'dismiss', 'keep', 'undo'])],
            'transaction_id' => ['nullable', 'uuid'],
        ]);

        try {
            $review->act($request->user(), $kind, $id, $validated['action'], $validated);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $review->items($request->user())]);
    }
}
