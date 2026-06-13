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
     * Block type that stores a user-authored note for an event. Mirrored in
     * EventsController::updateNote, which writes/clears this block.
     */
    public const NOTE_BLOCK_TYPE = 'note';

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

            // User-authored note lives in a dedicated `note` block. It surfaces as a
            // top-level `note` field (the iOS detail screen has its own note editor)
            // and is excluded from the generic blocks grid below to avoid duplication.
            // The blocks relation is withTrashed(), so ignore soft-deleted note
            // blocks (a cleared note) when surfacing the current note.
            $note = $this->blocks->first(
                fn ($block) => $block->block_type === self::NOTE_BLOCK_TYPE && $block->deleted_at === null,
            );

            if ($note) {
                $data['note'] = $note->getContent();
            }

            // Full blocks array only in the detail endpoint. The feed uses withCount('blocks')
            // which sets blocks_count, signalling list mode where the array would be too heavy.
            if (! isset($this->blocks_count)) {
                $blocks = $this->blocks->reject(
                    fn ($block) => $block->block_type === self::NOTE_BLOCK_TYPE,
                );
                $data['blocks'] = CompactBlockResource::collection($blocks)->resolve($request);
            }
        }

        if (isset($this->blocks_count)) {
            $data['blocks_count'] = $this->blocks_count;
        }

        $actionConfig = $this->actionConfig();
        $data['display_name'] = $actionConfig['display_name'] ?? format_action_title($this->action);
        $data['display_with_object'] = (bool) ($actionConfig['display_with_object'] ?? true);
        $data['hidden'] = (bool) ($actionConfig['hidden'] ?? false);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function actionConfig(): array
    {
        foreach ($this->pluginLookupKeys() as $service) {
            $pluginClass = PluginRegistry::getPlugin($service);

            if (! $pluginClass) {
                continue;
            }

            $actionConfig = $pluginClass::getActionTypes()[$this->action] ?? null;

            if ($actionConfig !== null) {
                return $actionConfig;
            }
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function pluginLookupKeys(): array
    {
        $services = [$this->service];

        if ($this->relationLoaded('integration') && $this->integration?->service) {
            $services[] = $this->integration->service;
        }

        return collect($services)
            ->filter(fn ($service) => is_string($service) && $service !== '')
            ->flatMap(fn (string $service) => [
                $service,
                str_replace('_', '-', $service),
                str_replace('-', '_', $service),
            ])
            ->unique()
            ->values()
            ->all();
    }
}
