<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Knowledge\KnowledgeReprocessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class KnowledgeReprocessingController extends Controller
{
    public function __construct(
        protected KnowledgeReprocessingService $reprocessing,
    ) {}

    /**
     * POST /api/v1/mobile/knowledge/events/{id}/reprocess
     */
    public function store(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['sometimes', 'string', 'in:auto,summary_only,refetch'],
        ]);

        try {
            $payload = $this->reprocessing->reprocessForUser(
                $request->user(),
                $id,
                $validated['mode'] ?? KnowledgeReprocessingService::MODE_AUTO,
            );
        } catch (RuntimeException $e) {
            if ($e->getCode() === 404) {
                return response()->json(['message' => $e->getMessage()], 404);
            }

            throw $e;
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($payload, 202);
    }
}
