<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Mobile\DeltaSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(protected DeltaSync $sync) {}

    /**
     * GET /api/v1/mobile/sync/delta?since={cursor}
     */
    public function delta(Request $request): JsonResponse
    {
        $cursor = $request->query('since');
        $cursor = is_string($cursor) && $cursor !== '' ? $cursor : null;

        return response()->json($this->sync->delta($request->user(), $cursor, $request));
    }
}
