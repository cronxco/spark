<?php

namespace App\Http\Resources\Compact;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\EventObject
 */
class CompactObjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'concept' => $this->concept,
            'type' => $this->type,
            'title' => $this->title,
            'time' => $this->time?->toIso8601String(),
        ];

        if ($this->content) {
            $data['content'] = $this->content;
        }

        if ($this->url) {
            $data['url'] = $this->url;
        }

        if ($this->media_url) {
            $data['media_url'] = $this->media_url;
        }

        return $data;
    }
}
