<?php

namespace App\Ai\Tools\AskFlint;

use App\Services\Actions\ActionOutcome;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;

/**
 * Edits the plain fields of an event, object or block the user owns.
 */
class UpdateRecordTool extends AskFlintWriteTool
{
    /** The fields each kind of record lets Flint change. */
    private const FIELDS = [
        'event' => ['action', 'value', 'value_unit', 'time'],
        'object' => ['title', 'type', 'concept', 'url'],
        'block' => ['title', 'block_type', 'value', 'value_unit', 'time', 'url'],
    ];

    public function name(): string
    {
        return 'update-record';
    }

    public function description(): string
    {
        return 'Edit fields of one event, object or block the user owns. Events: action, value, value_unit, time. Objects: title, type, concept, url. Blocks: title, block_type, value, value_unit, time, url. Send only the fields to change. `value` is the stored number as the read tools return it. Applies immediately; the app offers Undo.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()->enum(['event', 'object', 'block'])->description('Which kind of record.')->required(),
            'id' => $schema->string()->description('UUID of the record, from a read tool.')->required(),
            'title' => $schema->string()->description('New title (objects and blocks).'),
            'action' => $schema->string()->description('New action verb, snake_case (events).'),
            'value' => $schema->number()->description('New numeric value (events and blocks).'),
            'value_unit' => $schema->string()->description('New unit for the value (events and blocks).'),
            'time' => $schema->string()->description('New time, ISO 8601 with offset (events and blocks).'),
            'type' => $schema->string()->description('New type (objects).'),
            'concept' => $schema->string()->description('New concept (objects).'),
            'block_type' => $schema->string()->description('New block type (blocks).'),
            'url' => $schema->string()->description('New URL (objects and blocks).'),
        ];
    }

    protected function apply(Request $request): array
    {
        $kind = (string) $request['kind'];
        $id = (string) $request['id'];

        if (! isset(self::FIELDS[$kind]) || ! Str::isUuid($id)) {
            return ['status' => 'failed', 'error' => 'kind must be event, object or block, and id a UUID.'];
        }

        $attributes = collect($request->all())
            ->only(self::FIELDS[$kind])
            ->reject(fn (mixed $value): bool => $value === null)
            ->all();

        if ($attributes === []) {
            return ['status' => 'failed', 'error' => "Nothing to change. A {$kind} accepts: " . implode(', ', self::FIELDS[$kind]) . '.'];
        }

        $attributes = $this->mutations->validateUpdate($kind, $attributes);
        $before = $this->mutations->find($this->user, $kind, $id);

        if ($before === null) {
            return $this->notFound(ucfirst($kind));
        }

        $previous = collect(array_keys($attributes))
            ->mapWithKeys(fn (string $field): array => [$field => $before->getRawOriginal($field)])
            ->all();

        $entity = match ($kind) {
            'event' => $this->mutations->updateEvent($this->user, $id, $attributes),
            'object' => $this->mutations->updateObject($this->user, $id, $attributes),
            'block' => $this->mutations->updateBlock($this->user, $id, $attributes),
        };

        if ($entity === null) {
            return $this->notFound(ucfirst($kind));
        }

        $summary = collect($attributes)
            ->map(fn (mixed $value, string $field): string => sprintf(
                '%s: %s → %s',
                str_replace('_', ' ', $field),
                $this->show($previous[$field] ?? null),
                $this->show($value),
            ))
            ->implode('; ');

        $receipt = $this->executor->record(
            $this->user,
            $this->action('flint.ask_edit'),
            ['kind' => $kind, 'id' => $id],
            ActionOutcome::succeeded('Record updated', ['kind' => $kind, 'id' => $id, 'fields' => array_keys($attributes)], [
                'kind' => $kind,
                'id' => $id,
                'previous' => $previous,
            ]),
        );

        return $this->done($this->changes->add($receipt, 'update', $kind, $entity, $summary));
    }

    private function show(mixed $value): string
    {
        return $value === null || $value === '' ? 'empty' : Str::limit((string) $value, 60);
    }
}
