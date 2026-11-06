<?php

namespace App\Http\Resources\Compact;

use App\Models\Block;
use App\Support\BlockDetails;
use App\Support\BlockPresentation;
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

        // How the card presents the block: the plugin's display name and
        // glyph, its domain (which tints the glyph and hero value), whether a
        // model wrote it, and whether the plugin hides the type. The same
        // values drive the web's card shell.
        $presentation = BlockPresentation::for($this->resource);
        $data['display_name'] = $presentation->label;
        $data['icon'] = $presentation->icon;
        $data['domain'] = $presentation->domain;
        $data['ai_written'] = $presentation->aiWritten;
        $data['hidden'] = $presentation->hidden;

        // Scope citations to the viewer's own integrations. The ids come from
        // stored block metadata written by a routine that reads inbound mail,
        // so an unscoped lookup would resolve any UUID in the table and return
        // another user's event title.
        $references = EntityReferenceResolver::resolveEvents(
            $this->metadata['referenced_event_ids'] ?? [],
            $request->user()?->integrations()->pluck('id'),
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

        $details = BlockDetails::for($this->resource);
        if ($details !== null) {
            $data['details'] = $details;
        }

        return $data;
    }
}
