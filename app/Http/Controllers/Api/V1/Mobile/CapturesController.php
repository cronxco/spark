<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Exceptions\UnsafeUrlException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Mobile\StoreCaptureRequest;
use App\Services\Capture\CaptureService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class CapturesController extends Controller
{
    public function __construct(protected CaptureService $captures) {}

    public function store(StoreCaptureRequest $request): JsonResponse
    {
        try {
            $result = $this->captures->capture($request->user(), $request->validated());
        } catch (UnsafeUrlException) {
            return response()->json([
                'message' => 'This URL is not allowed.',
                'errors' => ['url' => ['This URL is not allowed.']],
            ], 422);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['image' => [$exception->getMessage()]],
            ], 422);
        }

        return response()->json(['capture_receipt' => $result['receipt']], $result['created'] ? 201 : 200);
    }
}
