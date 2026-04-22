<?php

namespace App\Mcp\Tools;

use App\Http\Resources\BlockResource;
use App\Models\Block;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsIdempotent]
#[IsReadOnly]
class GetBlockTool extends Tool
{
    /**
     * The tool's description.
     */
    protected string $description = <<<'MARKDOWN'
        Retrieve full details for a specific block by its UUID.
        Returns the complete block with its content, metadata, and parent event context.
    MARKDOWN;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $user = $request->user();

        if (! $user) {
            return Response::error('Authentication required.');
        }

        $blockId = $request->get('id');

        if (empty($blockId)) {
            return Response::error('Block ID is required.');
        }

        // Validate UUID format
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $blockId)) {
            return Response::error('Invalid block ID format. Expected UUID.');
        }

        // Get user's integration IDs for authorization
        $userIntegrationIds = $user->integrations()->pluck('id')->toArray();

        if (empty($userIntegrationIds)) {
            return Response::error('No integrations found for user.');
        }

        // Find the block with authorization via event
        $block = Block::query()
            ->where('id', $blockId)
            ->whereHas('event', fn ($q) => $q->whereIn('integration_id', $userIntegrationIds))
            ->with(['event.integration', 'event.actor', 'event.target'])
            ->first();

        if (! $block) {
            return Response::error('Block not found or access denied.');
        }

        $result = (new BlockResource($block))->resolve(request());

        return Response::text(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('The UUID of the block to retrieve.')
                ->required(),
        ];
    }
}
