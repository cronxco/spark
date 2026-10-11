<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Money\NetWorth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NetWorthController extends Controller
{
    public function __construct(protected NetWorth $netWorth) {}

    /**
     * GET /api/v1/mobile/money/net-worth?compare=1month
     *
     * Net worth and its change over a window in one request. The figures come from the shared
     * {@see NetWorth} service, which the web Worth view also uses.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'compare' => ['nullable', 'string', 'in:' . implode(',', array_keys(NetWorth::WINDOWS))],
        ]);

        $result = $this->netWorth->build($request->user(), $validated['compare'] ?? '1month');

        return response()->json([
            'data' => [
                'total' => $result['total'],
                'currency' => $result['currency'],
                'comparison' => $result['comparison'],
                'excluded_accounts' => $result['excluded_accounts'],
                'as_of' => now()->toIso8601String(),
            ],
        ]);
    }
}
