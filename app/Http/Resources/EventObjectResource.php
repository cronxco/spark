<?php

namespace App\Http\Resources;

use App\Models\EventObject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventObject
 */
class EventObjectResource extends JsonResource
{
    /**
     * Condensed mode returns fewer fields for nested relationships.
     */
    protected bool $condensed = false;

    /**
     * Create a condensed instance of the resource.
     */
    public static function condensed(mixed $resource): self
    {
        $instance = new self($resource);
        $instance->condensed = true;

        return $instance;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->condensed) {
            return $this->toCondensedArray();
        }

        return $this->toFullArray();
    }

    /**
     * Full representation with all fields (excluding embeddings).
     *
     * @return array<string, mixed>
     */
    protected function toFullArray(): array
    {
        $data = [
            'id' => $this->id,
            'concept' => $this->concept,
            'type' => $this->type,
            'title' => $this->title,
            'time' => $this->time?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
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

        if ($this->location_address) {
            $data['location_address'] = $this->location_address;
        }

        // Include metadata but exclude lock fields
        if ($this->metadata && is_array($this->metadata)) {
            $metadata = $this->metadata;
            unset($metadata['locked'], $metadata['locked_at']);

            if (! empty($metadata)) {
                $data['metadata'] = $metadata;
            }
        }

        return $data;
    }

    /**
     * Condensed representation for nested relationships.
     *
     * @return array<string, mixed>
     */
    protected function toCondensedArray(): array
    {
        $data = [
            'id' => $this->id,
            'concept' => $this->concept,
            'type' => $this->type,
            'title' => $this->title,
        ];

        if ($this->content) {
            $data['content'] = $this->content;
        }

        if ($this->url) {
            $data['url'] = $this->url;
        }

        return $data;
    }
}
