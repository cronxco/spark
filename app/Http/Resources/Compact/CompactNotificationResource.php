<?php

namespace App\Http\Resources\Compact;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * @mixin DatabaseNotification
 */
class CompactNotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = is_array($this->data) ? $this->data : [];

        return [
            'id' => (string) $this->id,
            'title' => (string) ($data['title'] ?? 'Notification'),
            'body' => $data['body'] ?? $data['message'] ?? null,
            'domain' => $this->domain($data),
            'is_read' => $this->read_at !== null,
            'received_at' => $this->created_at?->toJSON(),
            'entity' => $this->entity($data),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function domain(array $data): ?string
    {
        $domain = $data['domain'] ?? data_get($data, 'data.domain');

        return is_string($domain) && $domain !== '' ? $domain : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{kind: string, id: string}|null
     */
    private function entity(array $data): ?array
    {
        $kind = $data['entity_type'] ?? data_get($data, 'entity.kind');
        $id = $data['entity_id'] ?? data_get($data, 'entity.id');

        if (! is_string($kind) || ! is_string($id) || $kind === '' || $id === '') {
            return null;
        }

        $kind = match ($kind) {
            'event', 'object', 'metric', 'place', 'anomaly', 'integration' => $kind,
            'event_object' => 'object',
            default => null,
        };

        return $kind === null ? null : [
            'kind' => $kind,
            'id' => $id,
        ];
    }
}
