<?php

namespace App\Mcp\Tools;

use App\Http\Resources\Compact\CompactIntegrationResource;
use App\Mcp\Concerns\RequiresSparkAbility;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-integrations')]
#[IsIdempotent]
#[IsReadOnly]
class ListIntegrationsTool extends Tool
{
    use RequiresSparkAbility;

    protected string $description = 'List the authenticated user\'s integrations, including their service, state, and sync metadata.';

    public function handle(Request $request): Response
    {
        if ($error = $this->requireAbility($request, 'integrations:read')) {
            return $error;
        }

        $integrations = $request->user()->integrations()->orderBy('service')->get();

        return Response::json([
            'data' => CompactIntegrationResource::collection($integrations)->resolve(request()),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
