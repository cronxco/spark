<?php

namespace Tests\Feature\Services\Ai;

use App\Models\ActionProgress;
use App\Models\User;
use App\Services\Ai\SkillRegistry;
use App\Services\Ai\SkillRunner;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class SkillRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openai.api_key' => 'test-key',
            'services.openai.models.reasoning' => 'test-reasoning-model',
            'services.flint_routine.cronxtools_url' => 'https://mcp.example.test/token/sse',
        ]);
    }

    #[Test]
    public function it_accepts_only_a_completed_response_with_the_required_write_tool(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->completedBody())]);
        $user = User::factory()->create();
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        $result = app(SkillRunner::class)->run($user, $skill, ['routine' => 'news_roundup']);

        $this->assertSame('evt-created', $result->eventId);
        $this->assertSame('resp-one', $result->responseId);
        $this->assertSame(15, $result->inputTokens);
        $this->assertSame(5, $result->outputTokens);
        $this->assertCount(1, $result->continuation->mcpListTools);

        Http::assertSent(function ($request) use ($skill) {
            $this->assertTrue($request['stream']);
            $this->assertSame($skill->maxToolCalls, $request['max_tool_calls']);
            $this->assertArrayNotHasKey('max_tool_calls', $request['tools'][0]);

            return true;
        });
    }

    #[Test]
    public function it_rejects_incomplete_tool_errors_and_missing_required_writes(): void
    {
        $user = User::factory()->create();
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        foreach ([
            ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'output' => []],
            $this->completedBody([['type' => 'mcp_call', 'name' => 'spark__create-flint-digest', 'output' => ['isError' => true]]]),
            $this->completedBody([['type' => 'message', 'content' => [['text' => 'I forgot to persist it.']]]]),
        ] as $body) {
            Http::fake(['api.openai.com/v1/responses' => Http::response($body)]);

            try {
                app(SkillRunner::class)->run($user, $skill, ['routine' => 'news_roundup']);
                $this->fail('An invalid terminal response was accepted.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    #[Test]
    public function streamed_tool_progress_and_continuation_are_forwarded_without_persisting_schemas(): void
    {
        $terminal = $this->completedBody();
        $stream = implode("\n\n", [
            'data: ' . json_encode(['type' => 'response.mcp_list_tools.in_progress']),
            'data: ' . json_encode(['type' => 'response.mcp_call.in_progress', 'name' => 'spark__create-flint-digest']),
            'data: ' . json_encode(['type' => 'response.mcp_call.completed', 'name' => 'spark__create-flint-digest']),
            'data: ' . json_encode(['type' => 'response.completed', 'response' => $terminal]),
        ]) . "\n\n";
        Http::fakeSequence()
            ->push($stream, 200, ['Content-Type' => 'text/event-stream'])
            ->push($this->completedBody(), 200);

        $user = User::factory()->create();
        $progress = ActionProgress::createProgress($user->id, 'flint_skill', 'run-one', 'queued', 'Queued');
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');
        $first = app(SkillRunner::class)->run($user, $skill, [], $progress);
        app(SkillRunner::class)->run($user, $skill, [], null, $first->continuation);

        $progress->refresh();
        $steps = collect($progress->updates)->pluck('step');
        $this->assertTrue($steps->contains('connecting'));
        $this->assertTrue($steps->contains('discovering_tools'));
        $this->assertTrue($steps->contains('tool_starting'));
        $this->assertTrue($steps->contains('tool_completed'));
        $this->assertStringNotContainsString('inputSchema', json_encode($progress->toArray()));

        Http::assertSent(fn ($request) => ($request['previous_response_id'] ?? null) === 'resp-one');
    }

    /** @param array<int, array<string, mixed>>|null $output */
    private function completedBody(?array $output = null): array
    {
        return [
            'id' => 'resp-one',
            'status' => 'completed',
            'error' => null,
            'incomplete_details' => null,
            'output' => $output ?? [
                ['type' => 'mcp_list_tools', 'tools' => [['name' => 'spark__create-flint-digest', 'inputSchema' => ['type' => 'object']]]],
                [
                    'type' => 'mcp_call',
                    'name' => 'spark__create-flint-digest',
                    'output' => json_encode(['content' => [['type' => 'text', 'text' => json_encode(['event_id' => 'evt-created'])]]]),
                ],
                ['type' => 'message', 'content' => [['text' => 'Done.']]],
            ],
            'usage' => ['input_tokens' => 15, 'output_tokens' => 5],
        ];
    }
}
