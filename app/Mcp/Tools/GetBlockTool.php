<?php

namespace App\Mcp\Tools;

use App\Http\Resources\BlockResource;
use App\Services\Mobile\BlockLookup;
use App\Services\Mobile\EventLookup;
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

        if (! preg_match(EventLookup::UUID_REGEX, $blockId)) {
            return Response::error('Invalid block ID format. Expected UUID.');
        }

        $block = app(BlockLookup::class)->find($user, $blockId);

        if (! $block) {
            return Response::error('Block not found or access denied.');
        }

        $result = (new BlockResource($block))->resolve(request());

        return Response::text(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\Contracts\JsonSchema\JsonSchema>
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
