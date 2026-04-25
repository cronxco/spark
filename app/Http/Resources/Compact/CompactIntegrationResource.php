<?php

namespace App\Http\Resources\Compact;

use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Integration
 */
class CompactIntegrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service' => $this->service,
            'name' => $this->name,
            'instance_type' => $this->instance_type,
            'status' => $this->status,
        ];
    }
}
