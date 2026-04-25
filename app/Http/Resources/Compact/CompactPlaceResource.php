<?php

namespace App\Http\Resources\Compact;

use App\Models\EventObject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventObject
 *
 * A Place is an EventObject with concept='place' — this resource surfaces
 * the geographic fields the iOS map view needs without the heavier body.
 */
class CompactPlaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        $data = [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type,
        ];

        if (isset($metadata['latitude'], $metadata['longitude'])) {
            $data['latitude'] = (float) $metadata['latitude'];
            $data['longitude'] = (float) $metadata['longitude'];
        }

        if ($this->location_address) {
            $data['address'] = $this->location_address;
        }

        if (isset($metadata['category'])) {
            $data['category'] = $metadata['category'];
        }

        return $data;
    }
}
