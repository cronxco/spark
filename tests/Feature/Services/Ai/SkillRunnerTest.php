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
    public function it_does_not_crash_when_tool_output_content_is_a_string(): void
    {
        $user = User::factory()->create();
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');
        $body = $this->completedBody([
            [
                'type' => 'mcp_call',
                'name' => 'spark__create-flint-digest',
                'output' => json_encode(['content' => 'plain string content', 'event_id' => 'evt-created']),
            ],
        ]);
        Http::fake(['api.openai.com/v1/responses' => Http::response($body)]);

        $result = app(SkillRunner::class)->run($user, $skill, ['routine' => 'news_roundup']);

        $this->assertSame(['spark__create-flint-digest'], $result->toolsCalled);
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

    #[Test]
    public function research_tools_are_served_by_the_you_server_under_their_own_names(): void
    {
        config(['services.flint_routine.you_mcp_url' => 'https://api.you.com/mcp?profile=free']);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->completedBody([
            ['type' => 'mcp_call', 'server_label' => 'you', 'name' => 'you-search', 'output' => '{"results":{}}'],
            [
                'type' => 'mcp_call',
                'server_label' => 'cronxtools',
                'name' => 'spark__create-flint-digest',
                'output' => json_encode(['event_id' => 'evt-created']),
            ],
        ]))]);
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        $result = app(SkillRunner::class)->run(User::factory()->create(), $skill, ['routine' => 'news_roundup']);

        $this->assertSame(['you__you-search', 'spark__create-flint-digest'], $result->toolsCalled);
        Http::assertSent(function ($request) {
            [$cronxTools, $you] = $request['tools'];
            $this->assertCount(2, $request['tools']);
            $this->assertSame('cronxtools', $cronxTools['server_label']);
            $this->assertNotContains('you__you-search', $cronxTools['allowed_tools']);
            $this->assertContains('spark__create-flint-digest', $cronxTools['allowed_tools']);
            $this->assertSame('you', $you['server_label']);
            $this->assertSame('https://api.you.com/mcp?profile=free', $you['server_url']);
            $this->assertSame(['you-search'], $you['allowed_tools']);
            $this->assertSame('never', $you['require_approval']);

            return true;
        });
    }

    #[Test]
    public function a_you_api_key_travels_as_the_bearer_token_not_in_the_url(): void
    {
        config([
            'services.flint_routine.you_mcp_url' => 'https://api.you.com/mcp',
            'services.flint_routine.you_mcp_key' => 'ydc-test-key',
        ]);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->completedBody())]);
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        app(SkillRunner::class)->run(User::factory()->create(), $skill, ['routine' => 'news_roundup']);

        Http::assertSent(function ($request) {
            $you = $request['tools'][1];
            $this->assertSame('https://api.you.com/mcp', $you['server_url']);
            $this->assertSame('ydc-test-key', $you['authorization']);
            $this->assertArrayNotHasKey('authorization', $request['tools'][0]);

            return true;
        });
    }

    #[Test]
    public function a_skill_still_runs_without_research_when_no_you_url_is_configured(): void
    {
        config(['services.flint_routine.you_mcp_url' => null]);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->completedBody())]);
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        app(SkillRunner::class)->run(User::factory()->create(), $skill, ['routine' => 'news_roundup']);

        Http::assertSent(function ($request) {
            $this->assertCount(1, $request['tools']);
            $this->assertSame('cronxtools', $request['tools'][0]['server_label']);

            return true;
        });
    }

    #[Test]
    public function a_skill_without_research_tools_never_connects_to_the_you_server(): void
    {
        config(['services.flint_routine.you_mcp_url' => 'https://api.you.com/mcp?profile=free']);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->completedBody())]);
        $skill = app(SkillRegistry::class)->get('flint-topics');

        try {
            app(SkillRunner::class)->run(User::factory()->create(), $skill, ['routine' => 'topics']);
        } catch (RuntimeException) {
            // flint-topics has its own required tools; only the request shape matters here.
        }

        Http::assertSent(fn ($request) => count($request['tools']) === 1);
    }

    #[Test]
    public function an_unlisted_you_tool_is_rejected(): void
    {
        config(['services.flint_routine.you_mcp_url' => 'https://api.you.com/mcp?profile=free']);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->completedBody([
            ['type' => 'mcp_call', 'server_label' => 'you', 'name' => 'you-contents', 'output' => '{}'],
        ]))]);
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unapproved tool');

        app(SkillRunner::class)->run(User::factory()->create(), $skill, ['routine' => 'news_roundup']);
    }

    #[Test]
    public function a_you_tool_name_from_another_server_is_not_mistaken_for_research(): void
    {
        config(['services.flint_routine.you_mcp_url' => 'https://api.you.com/mcp?profile=free']);
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->completedBody([
            ['type' => 'mcp_call', 'server_label' => 'cronxtools', 'name' => 'you-search', 'output' => '{}'],
        ]))]);
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        $this->expectException(RuntimeException::class);

        app(SkillRunner::class)->run(User::factory()->create(), $skill, ['routine' => 'news_roundup']);
    }

    #[Test]
    public function a_dry_run_declines_and_records_writes_but_lets_reads_through(): void
    {
        $paused = [
            'id' => 'resp-paused',
            'status' => 'completed',
            'output' => [
                ['type' => 'mcp_list_tools', 'tools' => [['name' => 'spark__manage-flint-topic']]],
                [
                    'type' => 'mcp_approval_request',
                    'id' => 'mcpr-list',
                    'server_label' => 'cronxtools',
                    'name' => 'spark__manage-flint-topic',
                    'arguments' => json_encode(['operation' => 'list']),
                ],
                [
                    'type' => 'mcp_approval_request',
                    'id' => 'mcpr-digest',
                    'server_label' => 'cronxtools',
                    'name' => 'spark__create-flint-digest',
                    'arguments' => json_encode(['title' => 'News — 10 Oct', 'blocks' => []]),
                ],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
        ];
        $finished = $this->completedBody([['type' => 'message', 'content' => [['text' => 'Run notes.']]]]);
        Http::fakeSequence()->push($paused)->push($finished);
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        $result = app(SkillRunner::class)->run(User::factory()->create(), $skill, ['routine' => 'news_roundup'], dryRun: true);

        $this->assertSame([[
            'tool' => 'spark__create-flint-digest',
            'arguments' => ['title' => 'News — 10 Oct', 'blocks' => []],
        ]], $result->capturedWrites);
        $this->assertSame('Run notes.', $result->text);
        $this->assertSame(25, $result->inputTokens);
        $this->assertArrayNotHasKey('captured_writes', $result->toArray());

        $requests = Http::recorded()->map(fn ($pair) => $pair[0]);
        $this->assertContains('spark__create-flint-digest', $requests[0]['tools'][0]['require_approval']['always']['tool_names']);
        $this->assertNotContains('spark__get-event-tool', $requests[0]['tools'][0]['require_approval']['always']['tool_names']);
        $this->assertSame('resp-paused', $requests[1]['previous_response_id']);
        $this->assertSame([
            ['type' => 'mcp_approval_response', 'approval_request_id' => 'mcpr-list', 'approve' => true],
            ['type' => 'mcp_approval_response', 'approval_request_id' => 'mcpr-digest', 'approve' => false],
        ], $requests[1]['input']);
    }

    #[Test]
    public function a_normal_run_never_asks_for_approval(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response($this->completedBody())]);
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        $result = app(SkillRunner::class)->run(User::factory()->create(), $skill, ['routine' => 'news_roundup']);

        $this->assertSame([], $result->capturedWrites);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['tools'][0]['require_approval'] === 'never');
    }

    #[Test]
    public function a_dry_run_rejects_an_approval_request_for_an_unlisted_tool(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'id' => 'resp-paused',
            'status' => 'completed',
            'output' => [[
                'type' => 'mcp_approval_request',
                'id' => 'mcpr-x',
                'server_label' => 'cronxtools',
                'name' => 'komodo__deploy_stack',
                'arguments' => '{}',
            ]],
        ])]);
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        $this->expectException(RuntimeException::class);
        app(SkillRunner::class)->run(User::factory()->create(), $skill, ['routine' => 'news_roundup'], dryRun: true);
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
