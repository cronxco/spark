<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Mobile\AnomalyAcknowledgement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnomaliesController extends Controller
{
    public function __construct(protected AnomalyAcknowledgement $acknowledgement) {}

    /**
     * POST /api/v1/mobile/anomalies/{id}/acknowledge
     */
    public function acknowledge(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
            'suppress_until' => ['nullable', 'date'],
        ]);

        $metadata = [];
        if (! empty($validated['note'])) {
            $metadata['acknowledgement_note'] = $validated['note'];
        }
        if (! empty($validated['suppress_until'])) {
            $metadata['suppress_until'] = $validated['suppress_until'];
        }

        $ok = $this->acknowledgement->acknowledge($request->user(), $id, $metadata);

        if (! $ok) {
            return response()->json(['message' => 'Anomaly not found.'], 404);
        }

        return response()->json(['acknowledged' => true]);
    }
}
