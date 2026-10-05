<?php

namespace App\Services;

use App\Http\Resources\Compact\CompactEventResource;
use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Source fields belong to the integration that produced an item. A user
 * cannot override them (EOB-D3): the web editors, the v1/mobile API and MCP
 * reject the edit, while notes, tags and location stay editable. A locked
 * object also keeps its source fields whatever writes to it, including an
 * integration refresh (see EventObject::booted()).
 */
class SourceFieldGuard
{
    public const SOURCED_MESSAGE = 'This comes from an integration, so its :field can\'t be changed. Notes and tags can still be edited.';

    public const LOCKED_MESSAGE = 'This object is locked, so its :field can\'t be changed. Unlock it first.';

    /**
     * Services whose items the user authors inside Spark itself, so their
     * source fields remain the user's to edit.
     *
     * @var array<int, string>
     */
    public const USER_AUTHORED_SERVICES = ['flint', 'task', 'manual_log', 'daily_checkin', 'manual_account'];

    /**
     * The source fields of a model class.
     *
     * @return array<int, string>
     */
    public static function fields(Model $model): array
    {
        return match (true) {
            $model instanceof EventObject => EventObject::SOURCE_FIELDS,
            $model instanceof Block => Block::SOURCE_FIELDS,
            default => [],
        };
    }

    public function isIntegrationSourced(Model $model): bool
    {
        return match (true) {
            $model instanceof Event => ! in_array($model->service, self::USER_AUTHORED_SERVICES, true),
            $model instanceof Block => $model->block_type !== CompactEventResource::NOTE_BLOCK_TYPE
                && $model->event !== null
                && $this->isIntegrationSourced($model->event),
            $model instanceof EventObject => Event::withTrashed()
                ->where(fn ($query) => $query->where('actor_id', $model->id)->orWhere('target_id', $model->id))
                ->whereNotIn('service', self::USER_AUTHORED_SERVICES)
                ->exists(),
            default => false,
        };
    }

    /**
     * Whether the user may edit any source field of this item.
     */
    public function sourceFieldsEditable(Model $model): bool
    {
        if ($model instanceof EventObject && $model->isLocked()) {
            return false;
        }

        return ! $this->isIntegrationSourced($model);
    }

    /**
     * Reject a user edit that would change a protected source field.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    public function assertEditable(Model $model, array $attributes): void
    {
        $changed = collect(self::fields($model))
            ->filter(fn (string $field) => array_key_exists($field, $attributes) && $this->differs($model->getAttribute($field), $attributes[$field]))
            ->values();

        if ($changed->isEmpty()) {
            return;
        }

        $message = $this->isIntegrationSourced($model) ? self::SOURCED_MESSAGE : null;
        if ($message === null && $model instanceof EventObject && $model->isLocked()) {
            $message = self::LOCKED_MESSAGE;
        }
        if ($message === null) {
            return;
        }

        throw ValidationException::withMessages(
            $changed->mapWithKeys(fn (string $field) => [$field => str_replace(':field', $this->label($field), $message)])->all(),
        );
    }

    private function differs(mixed $current, mixed $incoming): bool
    {
        $normalise = fn (mixed $value): ?string => $value === null || $value === '' ? null : (string) $value;

        return $normalise($current) !== $normalise($incoming);
    }

    private function label(string $field): string
    {
        return match ($field) {
            'block_type' => 'type',
            'url' => 'URL',
            default => $field,
        };
    }
}
