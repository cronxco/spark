<?php

namespace App\Http\Resources\Compact;

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
        }

        if ($this->url) {
            $data['url'] = $this->url;
        }

        if ($this->relationLoaded('actor') && $this->actor) {
            $data['actor'] = [
                'id' => $this->actor->id,
                'title' => $this->actor->title,
                'concept' => $this->actor->concept,
            ];
        }

        if ($this->relationLoaded('target') && $this->target) {
            $target = [
                'id' => $this->target->id,
                'title' => $this->target->title,
                'concept' => $this->target->concept,
            ];

            if ($this->domain === 'knowledge' && $this->target->media_url) {
                $target['media_url'] = $this->target->media_url;
            }

            $data['target'] = $target;
        }

        if ($this->domain === 'knowledge' && $this->relationLoaded('blocks')) {
            $tldr = $this->blocks->firstWhere('block_type', 'fetch_tldr')
                ?? $this->blocks->firstWhere('block_type', 'newsletter_tldr');

            if ($tldr) {
                $data['tldr'] = $tldr->getContent();
            }

            $paragraph = $this->blocks->firstWhere('block_type', 'fetch_summary_paragraph')
                ?? $this->blocks->firstWhere('block_type', 'newsletter_summary_paragraph');

            if ($paragraph) {
                $data['summary_paragraph'] = $paragraph->getContent();
            }
        }

        return $data;
    }
}
