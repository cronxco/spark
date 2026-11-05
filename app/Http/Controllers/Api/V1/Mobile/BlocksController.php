<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Compact\CompactBlockResource;
use App\Http\Resources\Compact\CompactEventResource;
use App\Services\Api\ResourceVersion;
use App\Services\Mobile\BlockLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlocksController extends Controller
{
    public function __construct(protected BlockLookup $lookup, protected ResourceVersion $versions) {}

    /**
     * GET /api/v1/mobile/blocks/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $block = $this->lookup->find($request->user(), $id);

        if (! $block) {
            return response()->json(['message' => 'Block not found.'], 404);
        }

        $payload = (new CompactBlockResource($block))->resolve($request);

        // The parent event rides along so the block screen can link back to it.
        // Its blocks aren't loaded, so the nested event carries no blocks array.
        if ($block->event) {
            $payload['event'] = (new CompactEventResource($block->event))->resolve($request);
        }

        $response = response()->json($payload);

        if ($block->updated_at) {
            $response->header('Last-Modified', $block->updated_at->toRfc7231String());
        }
        $response->header('ETag', $this->versions->etag($block));

        return $response;
    }
}
