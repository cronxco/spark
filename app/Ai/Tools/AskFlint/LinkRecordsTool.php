<?php

namespace App\Ai\Tools\AskFlint;

use App\Services\Actions\ActionOutcome;
use App\Services\RelationshipTypeRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

/**
 * Links two of the user's records with a typed relationship.
 */
class LinkRecordsTool extends AskFlintWriteTool
{
    public function name(): string
    {
        return 'link-records';
    }

    public function description(): string
    {
        return 'Create a relationship from one of the user\'s records to another. Types: ' . implode(', ', $this->types()) . '. Applies immediately; the app offers Undo.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from_kind' => $schema->string()->enum(['event', 'object', 'block'])->description('Kind of the record the link starts at.')->required(),
            'from_id' => $schema->string()->description('UUID of the record the link starts at.')->required(),
            'to_kind' => $schema->string()->enum(['event', 'object', 'block'])->description('Kind of the record the link points to.')->required(),
            'to_id' => $schema->string()->description('UUID of the record the link points to.')->required(),
            'type' => $schema->string()->enum($this->types())->description('The relationship type.')->required(),
        ];
    }

    protected function apply(Request $request): array
    {
        $attributes = $this->mutations->validateRelationship([
            'to_kind' => $request['to_kind'],
            'to_id' => $request['to_id'],
            'type' => $request['type'],
        ]);

        $fromKind = (string) $request['from_kind'];
        $fromId = (string) $request['from_id'];
        $from = $this->mutations->find($this->user, $fromKind, $fromId);
        $relationship = $from === null ? null : $this->mutations->createRelationship($this->user, $fromKind, $fromId, $attributes);

        if ($from === null || $relationship === null) {
            return ['status' => 'failed', 'error' => 'Could not link those records: one was not found, the type is not allowed, or they are the same record.'];
        }

        $receipt = $this->executor->record(
            $this->user,
            $this->action('flint.ask_link'),
            ['kind' => $fromKind, 'id' => $fromId],
            ActionOutcome::succeeded('Records linked', ['relationship_id' => $relationship->id], ['relationship_id' => $relationship->id]),
        );

        $to = $this->mutations->find($this->user, $attributes['to_kind'], $attributes['to_id']);
        $summary = str_replace('_', ' ', $attributes['type']) . ' ' . ($to === null ? 'another record' : $this->changes::label($to));

        return $this->done($this->changes->add($receipt, 'link', $fromKind, $from, ucfirst($summary)));
    }

    /** @return array<int, string> */
    private function types(): array
    {
        return collect(RelationshipTypeRegistry::getTypes())
            ->filter(fn (array $config): bool => $config['user_creatable'] ?? true)
            ->keys()
            ->values()
            ->all();
    }
}
