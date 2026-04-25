<?php

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Event
 */
class EventResource extends JsonResource
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
            'time' => $this->time?->toIso8601String(),
            'service' => $this->service,
            'domain' => $this->domain,
            'action' => $this->action,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        // Value formatting (apply multiplier)
        if ($this->value !== null) {
            $data['value'] = $this->formatted_value;
            $data['unit'] = $this->value_unit;
        }

        // URL if present
        if ($this->url) {
            $data['url'] = $this->url;
        }

        // Location if present
        if ($this->location_address) {
            $data['location_address'] = $this->location_address;
        }

        // Actor relationship
        if ($this->relationLoaded('actor') && $this->actor) {
            $data['actor'] = EventObjectResource::condensed($this->actor);
        }

        // Target relationship
        if ($this->relationLoaded('target') && $this->target) {
            $data['target'] = EventObjectResource::condensed($this->target);
        }

        // Integration
        if ($this->relationLoaded('integration') && $this->integration) {
            $data['integration'] = new IntegrationResource($this->integration);
        }

        // Blocks
        if ($this->relationLoaded('blocks') && $this->blocks->isNotEmpty()) {
            $data['blocks'] = $this->blocks->map(fn ($block) => BlockResource::condensed($block))->values();
        }

        // Tags
        if ($this->relationLoaded('tags') && $this->tags->isNotEmpty()) {
            $data['tags'] = $this->tags->pluck('name')->values();
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
            'time' => $this->time?->toIso8601String(),
            'service' => $this->service,
            'domain' => $this->domain,
            'action' => $this->action,
        ];

        if ($this->value !== null) {
            $data['value'] = $this->formatted_value;
            $data['unit'] = $this->value_unit;
        }

        return $data;
    }
}
