<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresSparkAbility;
use App\Services\Api\EntityMutationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('manage-relationship')]
class ManageRelationshipTool extends Tool
{
    use RequiresSparkAbility;

    protected string $description = 'List, create, or delete an owned relationship between events, objects, and blocks. Creation prevents self-links and respects relationship directionality.';

    public function __construct(private EntityMutationService $mutations) {}

    public function handle(Request $request): Response
    {
        if ($error = $this->requireAbility($request, $request->get('operation') === 'list' ? 'data:read' : 'data:write')) {
            return $error;
        }
        $operation = $request->get('operation');
        if ($operation === 'delete') {
            return $this->mutations->deleteRelationship($request->user(), (string) $request->get('relationship_id')) ? Response::json(['deleted' => true]) : Response::error('Relationship not found or access denied.');
        }
        $kind = $request->get('kind');
        $id = $request->get('id');
        if (! in_array($kind, ['event', 'object', 'block'], true) || ! is_string($id)) {
            return Response::error('kind and id are required.');
        }
        if ($operation === 'list') {
            $items = $this->mutations->relationships($request->user(), $kind, $id);

            return $items === null ? Response::error('Entity not found or access denied.') : Response::json(['data' => $items]);
        }
        if ($operation !== 'create') {
            return Response::error('operation must be list, create, or delete.');
        }
        try {
            $attributes = $this->mutations->validateRelationship(['to_kind' => $request->get('to_kind'), 'to_id' => $request->get('to_id'), 'type' => $request->get('type'), 'value' => $request->get('value'), 'value_multiplier' => $request->get('value_multiplier'), 'value_unit' => $request->get('value_unit'), 'metadata' => $request->get('metadata')]);
        } catch (ValidationException $exception) {
            return Response::error($exception->validator->errors()->first());
        }
        $relationship = $this->mutations->createRelationship($request->user(), $kind, $id, $attributes);

        return $relationship ? Response::json($this->mutations->relationshipPayload($relationship)) : Response::error('Relationship endpoints, type, or ownership are invalid.');
    }

    public function schema(JsonSchema $schema): array
    {
        return ['operation' => $schema->string()->description('list, create, or delete.')->required(), 'kind' => $schema->string()->description('Source kind for list/create.'), 'id' => $schema->string()->description('Source UUID for list/create.'), 'relationship_id' => $schema->string()->description('Relationship UUID for delete.'), 'to_kind' => $schema->string()->description('Target kind for create.'), 'to_id' => $schema->string()->description('Target UUID for create.'), 'type' => $schema->string()->description('Registered relationship type for create.'), 'value' => $schema->number()->description('Optional value.'), 'value_multiplier' => $schema->number()->description('Optional value multiplier.'), 'value_unit' => $schema->string()->description('Optional unit.'), 'metadata' => $schema->object()->description('Optional metadata.')];
    }
}
