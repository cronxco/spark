<?php

namespace App\Http\Resources\Compact;

use App\Integrations\PluginRegistry;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Event
 *
 * Mobile-optimised event shape. Based on EventResource::toCondensedArray()
 * but with a fixed contract — the iOS client decodes this into Swift structs,
 * so the payload shape is load-bearing and should only change through
 * explicit migration.
 */
class CompactEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
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
            $data['display_value'] = format_event_display_value(
                $this->formatted_value,
                $this->value_unit,
                $this->service,
                $this->action,
            );
        }

        if ($this->url) {
            $data['url'] = $this->url;
        }

        if ($this->relationLoaded('actor') && $this->actor) {
            $data['actor'] = [
                'id' => $this->actor->id,
                'title' => $this->actor->title,
                'concept' => $this->actor->concept,
                'type' => $this->actor->type,
                'media_url' => $this->actor->media_url,
            ];
        }

        if ($this->relationLoaded('target') && $this->target) {
            $data['target'] = [
                'id' => $this->target->id,
                'title' => $this->target->title,
                'concept' => $this->target->concept,
                'type' => $this->target->type,
                'media_url' => $this->target->media_url,
            ];
        }

        if ($this->relationLoaded('tags')) {
            $data['tags'] = $this->tags->map(fn ($tag) => [
                'name' => $tag->name,
                'type' => $tag->type,
            ])->values()->all();
        }

        if ($this->relationLoaded('blocks')) {
            $tldr = $this->blocks->first(
                fn ($block) => str_contains($block->block_type, 'tldr'),
            );

            if ($tldr) {
                $data['tldr'] = $tldr->getContent();
            }

            // Full blocks array only in the detail endpoint. The feed uses withCount('blocks')
            // which sets blocks_count, signalling list mode where the array would be too heavy.
            if (! isset($this->blocks_count)) {
                $data['blocks'] = CompactBlockResource::collection($this->blocks)->resolve($request);
            }
        }

        if (isset($this->blocks_count)) {
            $data['blocks_count'] = $this->blocks_count;
        }

        $pluginClass = PluginRegistry::getPlugin($this->service);
        $actionConfig = $pluginClass ? ($pluginClass::getActionTypes()[$this->action] ?? []) : [];
        $data['display_name'] = $actionConfig['display_name'] ?? format_action_title($this->action);
        $data['hidden'] = (bool) ($actionConfig['hidden'] ?? false);

        return $data;
    }
}
