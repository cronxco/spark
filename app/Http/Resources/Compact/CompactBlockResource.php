<?php

namespace App\Http\Resources\Compact;

use App\Models\Block;
use App\Support\EntityReferenceResolver;
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

        $references = EntityReferenceResolver::resolveEvents(
            $this->metadata['referenced_event_ids'] ?? [],
        );

        $content = $this->resource->getContent();
        if ($content) {
            $data['content'] = EntityReferenceResolver::linkify($content, $references);
        }

        if (! empty($references)) {
            $data['references'] = $references;
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
