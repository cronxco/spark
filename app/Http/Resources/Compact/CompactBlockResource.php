<?php

namespace App\Http\Resources\Compact;

use App\Models\Block;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Block
 */
class CompactBlockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'block_type' => $this->block_type,
            'title' => $this->title,
            'time' => $this->time?->toIso8601String(),
        ];

        $content = $this->resource->getContent();
        if ($content) {
            $data['content'] = $content;
        }

        if ($this->value !== null) {
            $data['value'] = $this->formatted_value;
            $data['unit'] = $this->value_unit;
        }

        if ($this->media_url) {
            $data['media_url'] = $this->media_url;
        }

        return $data;
    }
}
