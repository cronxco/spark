<?php

namespace App\Http\Resources\Compact;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @mixin PersonalAccessToken
 *
 * Mobile shape for a personal access token. The iOS client decodes this into
 * the `ApiToken` Swift struct, so `id` is stringified and timestamps are
 * ISO-8601. Never includes the plaintext secret — that is only returned once,
 * at creation, by ApiTokensController::store.
 */
class ApiTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'abilities' => $this->abilities ?? [],
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
