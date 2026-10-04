<?php

namespace App\Services\Api;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Relationship;
use App\Models\User;
use App\Services\Mobile\BlockLookup;
use App\Services\Mobile\EventLookup;
use App\Services\Mobile\ObjectLookup;
use App\Services\RelationshipTypeRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Spatie\Tags\Tag;

/**
 * Ownership-scoped, non-destructive mutations shared by v1 and MCP.
 */
class EntityMutationService
{
    public function __construct(
        private EventLookup $events,
        private ObjectLookup $objects,
        private BlockLookup $blocks,
    ) {}

    /** @return array<string, mixed> */
    public function validateUpdate(string $kind, array $attributes): array
    {
        return Validator::make($attributes, match ($kind) {
            'event' => ['action' => ['sometimes', 'string', 'max:255'], 'value' => ['sometimes', 'nullable', 'numeric'], 'value_multiplier' => ['sometimes', 'nullable', 'numeric'], 'value_unit' => ['sometimes', 'nullable', 'string', 'max:50'], 'time' => ['sometimes', 'nullable', 'date']],
            'object' => ['title' => ['sometimes', 'required', 'string', 'max:255'], 'type' => ['sometimes', 'nullable', 'string', 'max:100'], 'concept' => ['sometimes', 'nullable', 'string', 'max:100'], 'url' => ['sometimes', 'nullable', 'url', 'max:500']],
            'block' => ['title' => ['sometimes', 'nullable', 'string', 'max:255'], 'block_type' => ['sometimes', 'nullable', 'string', 'max:100'], 'value' => ['sometimes', 'nullable', 'numeric'], 'value_multiplier' => ['sometimes', 'nullable', 'numeric'], 'value_unit' => ['sometimes', 'nullable', 'string', 'max:50'], 'time' => ['sometimes', 'nullable', 'date'], 'url' => ['sometimes', 'nullable', 'url', 'max:500']],
            default => ['__invalid_kind' => ['required']],
        })->validate();
    }

    /** @return array<string, mixed> */
    public function validateRelationship(array $attributes): array
    {
        return Validator::make($attributes, ['to_kind' => ['required', 'in:event,object,block'], 'to_id' => ['required', 'uuid'], 'type' => ['required', 'string'], 'value' => ['nullable', 'numeric'], 'value_multiplier' => ['nullable', 'numeric'], 'value_unit' => ['nullable', 'string', 'max:50'], 'metadata' => ['nullable', 'array']])->validate();
    }

    public function updateEvent(User $user, string $id, array $attributes): ?Event
    {
        $event = $this->events->find($user, $id);
        if (! $event) {
            return null;
        }
        $event->update($this->only($attributes, ['action', 'value', 'value_multiplier', 'value_unit', 'time']));

        return $event->fresh(['integration', 'actor', 'target', 'blocks', 'tags']);
    }

    public function find(User $user, string $kind, string $id): Event|EventObject|Block|null
    {
        return $this->entity($user, $kind, $id);
    }

    public function updateObject(User $user, string $id, array $attributes): ?EventObject
    {
        $object = $this->objects->find($user, $id);
        if (! $object) {
            return null;
        }
        $object->update($this->only($attributes, ['title', 'type', 'concept', 'url']));

        return $object->fresh('tags');
    }

    public function updateBlock(User $user, string $id, array $attributes): ?Block
    {
        $block = $this->blocks->find($user, $id);
        if (! $block) {
            return null;
        }
        $block->update($this->only($attributes, ['title', 'block_type', 'value', 'value_multiplier', 'value_unit', 'time', 'url']));

        return $block->fresh(['event.integration', 'event.actor', 'event.target']);
    }

    public function attachTag(User $user, string $kind, string $id, array $attributes): ?array
    {
        $entity = $this->entity($user, $kind, $id);
        if (! $entity || ! in_array($kind, ['event', 'object'], true)) {
            return null;
        }
        $tag = isset($attributes['tag_id'])
            ? $this->ownedTag($user, (string) $attributes['tag_id'])
            : Tag::findOrCreate(trim((string) ($attributes['name'] ?? '')), $attributes['type'] ?? null);
        if (! $tag || $tag->name === '') {
            return null;
        }
        $entity->attachTags([$tag]);

        return ['tag' => $tag, 'entity' => $entity->fresh('tags')];
    }

    public function detachTag(User $user, string $kind, string $id, string $tagId): ?Model
    {
        $entity = $this->entity($user, $kind, $id);
        if (! $entity || ! in_array($kind, ['event', 'object'], true) || ! $entity->tags()->whereKey($tagId)->exists()) {
            return null;
        }
        $entity->detachTags([Tag::find($tagId)]);

        return $entity->fresh('tags');
    }

    public function relationships(User $user, string $kind, string $id): ?array
    {
        $entity = $this->entity($user, $kind, $id);
        if (! $entity) {
            return null;
        }

        return $entity->allRelationships()->with(['from', 'to'])->get()->map(fn (Relationship $relationship) => $this->relationshipPayload($relationship))->all();
    }

    public function createRelationship(User $user, string $fromKind, string $fromId, array $attributes): ?Relationship
    {
        $from = $this->entity($user, $fromKind, $fromId);
        $toKind = (string) ($attributes['to_kind'] ?? '');
        $to = $this->entity($user, $toKind, (string) ($attributes['to_id'] ?? ''));
        $type = (string) ($attributes['type'] ?? '');
        if (! $from || ! $to || ! isset(RelationshipTypeRegistry::getTypes()[$type]) || ($from::class === $to::class && $from->id === $to->id)) {
            return null;
        }

        $relationship = Relationship::createRelationship([
            'user_id' => $user->id, 'from_type' => $from::class, 'from_id' => $from->id,
            'to_type' => $to::class, 'to_id' => $to->id, 'type' => $type,
            'value' => $attributes['value'] ?? null, 'value_multiplier' => $attributes['value_multiplier'] ?? null,
            'value_unit' => $attributes['value_unit'] ?? null, 'metadata' => $attributes['metadata'] ?? null,
        ]);
        $from->touch();

        return $relationship;
    }

    public function deleteRelationship(User $user, string $id): bool
    {
        $relationship = Relationship::query()->where('user_id', $user->id)->find($id);
        if (! $relationship) {
            return false;
        }
        $relationship->from?->touch();
        $relationship->to?->touch();
        $relationship->delete();

        return true;
    }

    public function relationshipPayload(Relationship $relationship): array
    {
        return ['id' => $relationship->id, 'type' => $relationship->type, 'from_type' => $this->kind($relationship->from_type), 'from_id' => $relationship->from_id, 'to_type' => $this->kind($relationship->to_type), 'to_id' => $relationship->to_id, 'value' => $relationship->formatted_value, 'value_unit' => $relationship->value_unit, 'metadata' => $relationship->metadata, 'created_at' => $relationship->created_at?->toIso8601String()];
    }

    private function entity(User $user, string $kind, string $id): Event|EventObject|Block|null
    {
        return match ($kind) {
            'event' => $this->events->find($user, $id), 'object' => $this->objects->find($user, $id), 'block' => $this->blocks->find($user, $id), default => null
        };
    }

    private function kind(string $class): string
    {
        return match ($class) {
            Event::class => 'event', EventObject::class => 'object', Block::class => 'block', default => 'unknown'
        };
    }

    private function only(array $attributes, array $allowed): array
    {
        return array_intersect_key($attributes, array_flip($allowed));
    }

    private function ownedTag(User $user, string $id): ?Tag
    {
        // Tags are shared taxonomy records. Ownership is enforced on the
        // taggable entity; accepting an existing tag must not expose it.
        return Tag::find($id);
    }
}
