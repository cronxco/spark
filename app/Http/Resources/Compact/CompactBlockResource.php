<?php

namespace App\Http\Resources\Compact;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Block
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
            $data['content'] = mb_strlen($content, 'UTF-8') > 500
                ? mb_substr($content, 0, 500, 'UTF-8') . '...'
                : $content;
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
