<?php

namespace App\Mcp\Tools;

use App\Http\Resources\Compact\CompactBlockResource;
use App\Http\Resources\Compact\CompactEventResource;
use App\Http\Resources\Compact\CompactObjectResource;
use App\Mcp\Concerns\RequiresSparkAbility;
use App\Services\Api\EntityMutationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('update-entity')]
class UpdateEntityTool extends Tool
{
    use RequiresSparkAbility;

    protected string $description = 'Safely update an event, object, or block you own. This tool never deletes records or changes integration ownership.';

    public function __construct(private EntityMutationService $mutations) {}

    public function handle(Request $request): Response
    {
        if ($error = $this->requireAbility($request, 'data:write')) {
            return $error;
        }
        $kind = $request->get('kind');
        $id = $request->get('id');
        $attributes = $request->get('attributes', []);
        if (! in_array($kind, ['event', 'object', 'block'], true) || ! is_string($id) || ! is_array($attributes)) {
            return Response::error('kind (event, object, or block), id, and attributes are required.');
        }
        try {
            $attributes = $this->mutations->validateUpdate($kind, $attributes);
        } catch (ValidationException $exception) {
            return Response::error($exception->validator->errors()->first());
        }
        $entity = match ($kind) {
            'event' => $this->mutations->updateEvent($request->user(), $id, $attributes),
            'object' => $this->mutations->updateObject($request->user(), $id, $attributes),
            'block' => $this->mutations->updateBlock($request->user(), $id, $attributes),
        };
        if (! $entity) {
            return Response::error(ucfirst($kind) . ' not found or access denied.');
        }
        $payload = match ($kind) {
            'event' => (new CompactEventResource($entity))->resolve(request()),
            'object' => (new CompactObjectResource($entity))->resolve(request()),
            'block' => (new CompactBlockResource($entity))->resolve(request()),
        };

        return Response::json($payload);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['kind' => $schema->string()->description('event, object, or block.')->required(), 'id' => $schema->string()->description('Entity UUID.')->required(), 'attributes' => $schema->object()->description('Allowed fields: event action/value/value_multiplier/value_unit/time; object title/type/concept/url; block title/block_type/value/value_multiplier/value_unit/time/url.')->required()];
    }
}
