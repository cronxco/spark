<?php

namespace Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * A tool that records the arguments it was called with and returns a fixed result.
 */
class RecordingTool implements Tool
{
    /** @var array<int, array<string, mixed>> */
    public array $received = [];

    /** @param array<string, mixed> $result */
    public function __construct(private array $result) {}

    public function name(): string
    {
        return 'search_thing';
    }

    public function description(): string
    {
        return 'Searches for a thing';
    }

    public function handle(Request $request): string
    {
        $this->received[] = $request->all();

        return (string) json_encode($this->result);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['query' => $schema->string()->required()];
    }
}
