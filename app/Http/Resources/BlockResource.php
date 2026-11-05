<?php

namespace App\Http\Resources;

use App\Models\Block;
use App\Support\FlintQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Block
 */
class BlockResource extends JsonResource
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
            'block_type' => $this->block_type,
            'title' => $this->title,
            'time' => $this->time?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        $content = $this->resource->getContent();
        if ($content) {
            $data['content'] = $content;
        }

        // Flint blocks keep their payload in metadata rather than content — a
        // question's text and answer, a day context's calendar and weather.
        // Without this the block reads back as a bare title, which made
        // `get-block-tool` useless for exactly the blocks a Flint routine is
        // told to link to by id.
        $data += $this->flintPayload();

        if ($this->url) {
            $data['url'] = $this->url;
        }

        if ($this->media_url) {
            $data['media_url'] = $this->media_url;
        }

        if ($this->value !== null) {
            $data['value'] = $this->formatted_value;
            $data['unit'] = $this->value_unit;
        }

        // Include event context if loaded
        if ($this->relationLoaded('event') && $this->event) {
            $data['event'] = [
                'id' => $this->event->id,
                'service' => $this->event->service,
                'domain' => $this->event->domain,
                'action' => $this->event->action,
                'time' => $this->event->time?->toIso8601String(),
            ];

            // Include integration if loaded
            if ($this->event->relationLoaded('integration') && $this->event->integration) {
                $data['event']['integration'] = new IntegrationResource($this->event->integration);
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
            'block_type' => $this->block_type,
            'title' => $this->title,
        ];

        $content = $this->resource->getContent();
        if ($content) {
            // Limit content length in condensed mode
            $data['content'] = mb_strlen($content, 'UTF-8') > 500
                ? mb_substr($content, 0, 500, 'UTF-8') . '...'
                : $content;
        }

        if ($this->value !== null) {
            $data['value'] = $this->formatted_value;
            $data['unit'] = $this->value_unit;
        }

        return $data;
    }

    /**
     * The structured payload a Flint block carries in metadata, keyed for the
     * block type. Empty for every other block type.
     *
     * @return array<string, mixed>
     */
    protected function flintPayload(): array
    {
        $meta = $this->metadata ?? [];

        return match ($this->block_type) {
            'flint_user_question' => [
                'question' => $meta['question'] ?? null,
                'topic' => $meta['topic'] ?? null,
                'priority' => $meta['priority'] ?? null,
                'answer_options' => $meta['answer_options'] ?? null,
                'answer' => $meta['answer'] ?? null,
                'answer_note' => $meta['answer_note'] ?? null,
                'answered_at' => $meta['answered_at'] ?? null,
                'answered' => FlintQuestion::isAnswered($this->resource),
                'retired_at' => $meta['retired_at'] ?? null,
                'retired' => FlintQuestion::isRetired($this->resource),
            ],
            'flint_day_context' => [
                'day_context' => $meta['day_context'] ?? null,
            ],
            default => array_filter([
                'referenced_event_ids' => $meta['referenced_event_ids'] ?? null,
            ]),
        };
    }
}
