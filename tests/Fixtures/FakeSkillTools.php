<?php

namespace Tests\Fixtures;

use App\Ai\Tools\Skills\SkillToolSource;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Stands in for the MCP servers a skill reaches. Each fake tool answers with
 * a fixed output (or a closure's), and every call is recorded.
 */
class FakeSkillTools extends SkillToolSource
{
    /** @var array<int, array{url: string, names: array<int, string>, token: ?string}> */
    public array $connections = [];

    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    public array $calls = [];

    /**
     * @param  array<string, string|Closure>  $spark  Bare Spark tool name => output
     * @param  array<string, array<string, string|Closure>>  $remote  Server URL => remote tool name => output
     */
    public function __construct(
        private array $spark = [],
        private array $remote = [],
        private bool $realSpark = false,
    ) {}

    public function spark(): array
    {
        $tools = $this->realSpark ? parent::spark() : [];
        foreach ($this->spark as $name => $output) {
            $tools[$name] = $this->tool("spark__{$name}", $output);
        }

        return $tools;
    }

    public function remote(string $url, array $names, ?string $token = null): array
    {
        $this->connections[] = ['url' => $url, 'names' => $names, 'token' => $token];
        $tools = [];
        foreach ($this->remote[$url] ?? [] as $name => $output) {
            if (in_array($name, $names, true)) {
                $tools[$name] = $this->tool($name, $output);
            }
        }

        return $tools;
    }

    public function disconnect(): void {}

    /** @return array<int, string> */
    public function calledTools(): array
    {
        return array_column($this->calls, 0);
    }

    private function tool(string $name, string|Closure $output): Tool
    {
        $calls = &$this->calls;

        return new class($name, $output, $calls) implements Tool
        {
            public function __construct(private string $name, private string|Closure $output, private array &$calls) {}

            public function description(): string
            {
                return "Fake {$this->name}";
            }

            public function handle(Request $request): string
            {
                $this->calls[] = [$this->name, $request->all()];

                return $this->output instanceof Closure ? ($this->output)($request->all()) : $this->output;
            }

            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };
    }
}
