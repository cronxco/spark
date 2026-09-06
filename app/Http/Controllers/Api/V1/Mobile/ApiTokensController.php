<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Compact\ApiTokenResource;
use App\Support\SparkAbility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApiTokensController extends Controller
{
    /**
     * Tokens we never expose or allow to be revoked through this endpoint —
     * the mobile app's own OAuth-issued tokens carry these abilities and
     * managing them here would let the app revoke its own session.
     */
    private const HIDDEN_ABILITIES = ['ios:read', 'ios:write'];

    /**
     * GET /api/v1/mobile/api-tokens
     *
     * Lists the user's personal access tokens (excluding the app's own
     * iOS session tokens). Never returns plaintext secrets.
     */
    public function index(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()
            ->latest()
            ->get()
            ->reject(fn ($token) => $this->isAppToken($token))
            ->values();

        return response()->json(
            ApiTokenResource::collection($tokens)->resolve($request),
        );
    }

    /**
     * POST /api/v1/mobile/api-tokens
     *
     * Creates a personal access token and returns the one-time plaintext.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'min:1', 'max:20'],
            'abilities.*' => ['string', 'distinct', Rule::in(SparkAbility::DELEGABLE)],
        ]);

        $abilities = array_values($validated['abilities']);

        // Authority attenuates: a credential may only mint capabilities it
        // already holds. Previously an omitted or all-hidden selection fell
        // back to ['*'], which let a narrow iOS session mint a token that
        // satisfies every capability gate in the application.
        if (! SparkAbility::canDelegate($request->user(), $abilities)) {
            return response()->json([
                'message' => 'The requested capabilities exceed those of the credential making this request.',
            ], 403);
        }

        $token = $request->user()->createToken($validated['name'], $abilities);

        return response()->json([
            'id' => (string) $token->accessToken->getKey(),
            'name' => $token->accessToken->name,
            'plaintext' => $token->plainTextToken,
        ], 201);
    }

    /**
     * DELETE /api/v1/mobile/api-tokens/{id}
     *
     * Revokes a token. The app's own iOS session tokens are not revocable here.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $token = $request->user()->tokens()->whereKey($id)->first();

        if (! $token || $this->isAppToken($token)) {
            return response()->json(['message' => 'Token not found.'], 404);
        }

        $token->delete();

        return response()->json([], 204);
    }

    /**
     * An app-session token is one issued for the iOS app itself (ios:* scopes).
     */
    private function isAppToken(object $token): bool
    {
        $abilities = $token->abilities ?? [];

        return ! empty(array_intersect($abilities, self::HIDDEN_ABILITIES));
    }
}
