<?php

namespace App\Ai\Tools\Skills;

use App\Mcp\Servers\SparkServer;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\McpTool;
use Laravel\Mcp\Client;
use ReflectionClass;

/**
 * Where a skill's tools come from: Spark's own MCP tools in-process, and
 * remote MCP servers through laravel/mcp's client.
 */
class SkillToolSource
{
    /** @var array<int, Client> */
    private array $clients = [];

    /**
     * Spark's MCP tools by name, resolved from the container as the server
     * would resolve them, ready to run in-process.
     *
     * @return array<string, Tool>
     */
    public function spark(): array
    {
        $classes = (new ReflectionClass(SparkServer::class))->getDefaultProperties()['tools'] ?? [];
        $tools = [];
        foreach ($classes as $class) {
            $tool = app($class);
            $tools[$tool->name()] = new McpServerTool($tool);
        }

        return $tools;
    }

    /**
     * The named tools a remote MCP server offers, by their name there.
     *
     * @param  array<int, string>  $names
     * @return array<string, Tool>
     */
    public function remote(string $url, array $names, ?string $token = null): array
    {
        $client = Client::web($url);
        if ($token !== null && $token !== '') {
            $client->withToken($token);
        }
        $this->clients[] = $client = $client->onlyTools($names);

        $tools = [];
        foreach ($client->tools() as $tool) {
            $tools[$tool->name] = new McpTool($tool);
        }

        return $tools;
    }

    /** Close every remote connection this source opened. */
    public function disconnect(): void
    {
        foreach ($this->clients as $client) {
            $client->disconnect();
        }
        $this->clients = [];
    }
}
