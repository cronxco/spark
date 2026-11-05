<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SparkServerProtocolTest extends TestCase
{
    use RefreshDatabase;

    private const PROTOCOL = '2026-07-28';

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = User::factory()->create()->createToken('test', ['*'])->plainTextToken;
    }

    #[Test]
    public function a_modern_client_can_discover_the_server(): void
    {
        $response = $this->withToken($this->token)->postJson(
            '/mcp/spark',
            $this->modernMessage('server/discover'),
            ['MCP-Protocol-Version' => self::PROTOCOL, 'Mcp-Method' => 'server/discover'],
        );

        $response->assertSuccessful();
        $response->assertJsonPath('result.supportedVersions.0', self::PROTOCOL);
        $this->assertStringContainsString('Spark is a personal data integration platform', $response->json('result.instructions'));
    }

    #[Test]
    public function a_modern_client_can_list_the_tools(): void
    {
        $response = $this->withToken($this->token)->postJson(
            '/mcp/spark',
            $this->modernMessage('tools/list'),
            ['MCP-Protocol-Version' => self::PROTOCOL, 'Mcp-Method' => 'tools/list'],
        );

        $response->assertSuccessful();
        $names = collect($response->json('result.tools'))->pluck('name');
        $this->assertTrue($names->contains('get-day-summary-tool'));
        $this->assertTrue($names->contains('run-flint-skill'));
    }

    #[Test]
    public function a_modern_client_can_call_a_tool(): void
    {
        $response = $this->withToken($this->token)->postJson(
            '/mcp/spark',
            $this->modernMessage('tools/call', ['name' => 'list-integrations', 'arguments' => []]),
            ['MCP-Protocol-Version' => self::PROTOCOL, 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'list-integrations'],
        );

        $response->assertSuccessful();
        $response->assertJsonMissingPath('error');
        $this->assertNotEmpty($response->json('result.content'));
    }

    #[Test]
    public function a_modern_request_without_the_mcp_headers_is_rejected(): void
    {
        $response = $this->withToken($this->token)->postJson(
            '/mcp/spark',
            $this->modernMessage('tools/call', ['name' => 'list-integrations', 'arguments' => []]),
        );

        $response->assertStatus(400);
        $response->assertJsonPath('error.code', -32020);
    }

    #[Test]
    public function a_legacy_client_can_still_initialize(): void
    {
        $response = $this->withToken($this->token)->postJson('/mcp/spark', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'legacy-client', 'version' => '0.1.0'],
            ],
        ]);

        $response->assertSuccessful();
        $response->assertJsonPath('result.protocolVersion', '2025-11-25');
        $response->assertJsonPath('result.serverInfo.name', 'Spark');
    }

    #[Test]
    public function a_legacy_client_can_call_a_tool_without_the_mcp_headers(): void
    {
        $response = $this->withToken($this->token)->postJson('/mcp/spark', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'list-integrations', 'arguments' => []],
        ]);

        $response->assertSuccessful();
        $response->assertJsonMissingPath('error');
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function modernMessage(string $method, array $params = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => [
                ...$params,
                '_meta' => [
                    'io.modelcontextprotocol/protocolVersion' => self::PROTOCOL,
                    'io.modelcontextprotocol/clientCapabilities' => (object) [],
                    'io.modelcontextprotocol/clientInfo' => ['name' => 'test-client', 'version' => '1.2.3'],
                ],
            ],
        ];
    }
}
