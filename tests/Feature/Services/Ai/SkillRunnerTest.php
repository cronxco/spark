<?php

namespace Tests\Feature\Services\Ai;

use App\Ai\Tools\Skills\SkillTool;
use App\Ai\Tools\Skills\SkillToolSource;
use App\Models\ActionProgress;
use App\Models\User;
use App\Services\Ai\SkillRegistry;
use App\Services\Ai\SkillRunner;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Fixtures\FakeOpenAi;
use Tests\Fixtures\FakeSkillTools;
use Tests\TestCase;

class SkillRunnerTest extends TestCase
{
    private const CRONXTOOLS = 'https://mcp.example.test/token/sse';

    private const YOU = 'https://api.you.com/mcp?profile=free';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openai.api_key' => 'test-key',
            'services.openai.models.reasoning' => 'test-reasoning-model',
            'services.flint_routine.cronxtools_url' => self::CRONXTOOLS,
            'services.flint_routine.you_mcp_url' => null,
        ]);
    }

    #[Test]
    public function it_accepts_a_run_that_completes_the_required_write_tool(): void
    {
        $tools = $this->fakeTools();
        FakeOpenAi::fake([
            FakeOpenAi::toolCalls([['spark__create-flint-digest', ['title' => 'News']]], ['input_tokens' => 10, 'output_tokens' => 2]),
            FakeOpenAi::text('Done.', ['input_tokens' => 5, 'output_tokens' => 3]),
        ]);
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        $result = app(SkillRunner::class)->run(User::factory()->create(), $skill, ['routine' => 'news_roundup']);

        $this->assertSame('evt-created', $result->eventId);
        $this->assertSame('Done.', $result->text);
        $this->assertSame(['spark__create-flint-digest'], $result->toolsCalled);
        $this->assertSame(15, $result->inputTokens);
        $this->assertSame(5, $result->outputTokens);
        $this->assertNotEmpty($result->responseId);
        $this->assertSame([['spark__create-flint-digest', ['title' => 'News']]], $tools->calls);

        $request = FakeOpenAi::requests()[0];
        $this->assertSame('test-reasoning-model', $request['model']);
        $this->assertSame(['routine' => 'news_roundup'], json_decode(collect($request['input'])->firstWhere('role', 'user')['content'][0]['text'], true));
        $this->assertStringContainsString('Flint News Roundup', json_encode($request['input']));
    }

    #[Test]
    public function only_the_manifest_tools_are_offered_under_their_manifest_names(): void
    {
        $this->fakeTools();
        FakeOpenAi::fake([FakeOpenAi::text('Nothing to do.')]);
        $skill = app(SkillRegistry::class)->get('flint-topics');

        try {
            app(SkillRunner::class)->run(User::factory()->create(), $skill, ['routine' => 'topics']);
        } catch (RuntimeException) {
            // flint-topics has required tools the model never called; only the offer matters here.
        }

        $offered = collect(FakeOpenAi::requests()[0]['tools'])->pluck('name')->sort()->values()->all();
        $this->assertSame([
            'spark__complete-flint-run',
            'spark__get-events-by-filter-tool',
            'spark__get-latest-flint-digest',
            'spark__manage-flint-topic',
        ], $offered);
        $this->assertSame('function', FakeOpenAi::requests()[0]['tools'][0]['type']);
    }

    #[Test]
    public function it_rejects_tool_errors_and_missing_required_writes(): void
    {
        $user = User::factory()->create();
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        foreach ([
            // The tool reports an error.
            [json_encode(['isError' => true]), [FakeOpenAi::toolCalls([['spark__create-flint-digest', []]]), FakeOpenAi::text('Done.')]],
            // The model never persists the digest.
            [json_encode(['event_id' => 'evt-created']), [FakeOpenAi::text('I forgot to persist it.')]],
            // The response stops short.
            [json_encode(['event_id' => 'evt-created']), [['id' => 'r', 'status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'output' => []]]],
        ] as [$output, $bodies]) {
            $this->fakeTools(['create-flint-digest' => $output]);
            FakeOpenAi::fake($bodies);

            try {
                app(SkillRunner::class)->run($user, $skill, ['routine' => 'news_roundup']);
                $this->fail('An invalid run was accepted.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('flint-news-roundup', $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_failed_optional_tool_goes_back_to_the_model_and_the_run_carries_on(): void
    {
        $tools = $this->fakeTools(remote: [self::CRONXTOOLS => [
            'docs__fetch' => 'MCP tool error: Error: Saved location "home" not found.',
        ]]);
        FakeOpenAi::fake([
            FakeOpenAi::toolCalls([['docs__fetch', ['id' => 'style']]]),
            FakeOpenAi::toolCalls([['spark__create-flint-digest', ['title' => 'News']]]),
            FakeOpenAi::text('Done.'),
        ]);
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        $result = app(SkillRunner::class)->run(User::factory()->create(), $skill, ['routine' => 'news_roundup']);

        $this->assertSame('evt-created', $result->eventId);
        $this->assertSame(['docs__fetch', 'spark__create-flint-digest'], $tools->calledTools());

        $handedBack = collect(FakeOpenAi::requests()[1]['input'])->firstWhere('type', 'function_call_output')['output'];
        $this->assertStringContainsString('docs__fetch failed', $handedBack);
        $this->assertStringContainsString('Saved location "home" not found', $handedBack);
    }

    #[Test]
    public function a_rejected_required_write_goes_back_to_the_model_to_correct_and_retry(): void
    {
        $attempts = 0;
        $this->fakeTools(['create-flint-digest' => function () use (&$attempts): string {
            return ++$attempts === 1
                ? 'MCP tool error: Routine block type/title pairs must be unique so one block cannot overwrite another.'
                : json_encode(['event_id' => 'evt-created']);
        }]);
        FakeOpenAi::fake([
            FakeOpenAi::toolCalls([['spark__create-flint-digest', ['title' => 'News']]]),
            FakeOpenAi::toolCalls([['spark__create-flint-digest', ['title' => 'News, corrected']]]),
            FakeOpenAi::text('Done.'),
        ]);
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        $result = app(SkillRunner::class)->run(User::factory()->create(), $skill, ['routine' => 'news_roundup']);

        $this->assertSame('evt-created', $result->eventId);
        $this->assertSame(2, $attempts);

        $handedBack = collect(FakeOpenAi::requests()[1]['input'])->firstWhere('type', 'function_call_output')['output'];
        $this->assertStringContainsString('must be unique', $handedBack);
        $this->assertStringContainsString('Correct the call and make it again', $handedBack);
    }

    #[Test]
    public function a_required_write_that_keeps_failing_ends_the_run(): void
    {
        $tools = $this->fakeTools(['create-flint-digest' => 'MCP tool error: Routine block type/title pairs must be unique.']);
        FakeOpenAi::fake(array_merge(
            array_fill(0, SkillTool::MAX_RETRIES + 1, FakeOpenAi::toolCalls([['spark__create-flint-digest', []]])),
            [FakeOpenAi::text('Done.')],
        ));
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        try {
            app(SkillRunner::class)->run(User::factory()->create(), $skill, ['routine' => 'news_roundup']);
            $this->fail('A write that never succeeded was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('spark__create-flint-digest failed', $e->getMessage());
        }

        $this->assertCount(SkillTool::MAX_RETRIES + 1, $tools->calls);
    }

    #[Test]
    public function a_call_to_a_tool_the_manifest_does_not_allow_fails_the_run_without_running_it(): void
    {
        $tools = $this->fakeTools(remote: [self::CRONXTOOLS => ['docs__fetch' => '{}', 'komodo__deploy_stack' => 'deployed']]);
        FakeOpenAi::fake([
            FakeOpenAi::toolCalls([['komodo__deploy_stack', []]]),
            FakeOpenAi::text('Done.'),
        ]);
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        try {
            app(SkillRunner::class)->run(User::factory()->create(), $skill, ['routine' => 'news_roundup']);
            $this->fail('A call to an unapproved tool was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('komodo__deploy_stack', $e->getMessage());
        }

        $this->assertSame([], $tools->calledTools());
        $this->assertNotContains('komodo__deploy_stack', collect(FakeOpenAi::requests()[0]['tools'])->pluck('name'));
    }

    #[Test]
    public function tool_progress_is_reported_without_persisting_schemas(): void
    {
        $this->fakeTools();
        FakeOpenAi::fake([
            FakeOpenAi::toolCalls([['spark__create-flint-digest', []]]),
            FakeOpenAi::text('Done.'),
        ]);
        $user = User::factory()->create();
        $progress = ActionProgress::createProgress($user->id, 'flint_skill', 'run-one', 'queued', 'Queued');
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        app(SkillRunner::class)->run($user, $skill, [], $progress);

        $progress->refresh();
        $steps = collect($progress->updates)->pluck('step');
        foreach (['connecting', 'discovering_tools', 'tool_starting', 'tool_completed', 'completed'] as $step) {
            $this->assertTrue($steps->contains($step), "Missing progress step {$step}");
        }
        $this->assertStringNotContainsString('inputSchema', json_encode($progress->toArray()));
    }

    #[Test]
    public function research_tools_are_served_by_the_you_server_under_their_own_names(): void
    {
        config(['services.flint_routine.you_mcp_url' => self::YOU]);
        $tools = $this->fakeTools(remote: [self::YOU => ['you-search' => '{"results":{}}']]);
        FakeOpenAi::fake([
            FakeOpenAi::toolCalls([['you__you-search', ['query' => 'budget']], ['spark__create-flint-digest', []]]),
            FakeOpenAi::text('Done.'),
        ]);
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        $result = app(SkillRunner::class)->run(User::factory()->create(), $skill, ['routine' => 'news_roundup']);

        $this->assertSame(['you__you-search', 'spark__create-flint-digest'], $result->toolsCalled);
        $this->assertSame([['you-search', ['query' => 'budget']], ['spark__create-flint-digest', []]], $tools->calls);
        [$cronxTools, $you] = $tools->connections;
        $this->assertSame(self::CRONXTOOLS, $cronxTools['url']);
        $this->assertSame(['docs__fetch'], $cronxTools['names']);
        $this->assertSame(self::YOU, $you['url']);
        $this->assertSame(['you-search'], $you['names']);
    }

    #[Test]
    public function a_you_api_key_travels_as_the_bearer_token_not_in_the_url(): void
    {
        config([
            'services.flint_routine.you_mcp_url' => 'https://api.you.com/mcp',
            'services.flint_routine.you_mcp_key' => 'ydc-test-key',
        ]);
        $tools = $this->fakeTools();
        FakeOpenAi::fake([FakeOpenAi::toolCalls([['spark__create-flint-digest', []]]), FakeOpenAi::text('Done.')]);
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        app(SkillRunner::class)->run(User::factory()->create(), $skill, ['routine' => 'news_roundup']);

        $this->assertNull($tools->connections[0]['token']);
        $this->assertSame('https://api.you.com/mcp', $tools->connections[1]['url']);
        $this->assertSame('ydc-test-key', $tools->connections[1]['token']);
    }

    #[Test]
    public function a_skill_runs_without_research_when_no_you_url_is_configured(): void
    {
        $tools = $this->fakeTools();
        FakeOpenAi::fake([FakeOpenAi::toolCalls([['spark__create-flint-digest', []]]), FakeOpenAi::text('Done.')]);
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        app(SkillRunner::class)->run(User::factory()->create(), $skill, ['routine' => 'news_roundup']);

        $this->assertSame([self::CRONXTOOLS], array_column($tools->connections, 'url'));
    }

    #[Test]
    public function a_skill_with_only_spark_tools_opens_no_remote_connection(): void
    {
        config(['services.flint_routine.you_mcp_url' => self::YOU]);
        $tools = $this->fakeTools();
        FakeOpenAi::fake([FakeOpenAi::text('Nothing to do.')]);
        $skill = app(SkillRegistry::class)->get('flint-topics');

        try {
            app(SkillRunner::class)->run(User::factory()->create(), $skill, ['routine' => 'topics']);
        } catch (RuntimeException) {
            // flint-topics has its own required tools; only the connections matter here.
        }

        $this->assertSame([], $tools->connections);
    }

    #[Test]
    public function a_required_tool_no_server_offers_fails_before_the_model_is_called(): void
    {
        $this->app->instance(SkillToolSource::class, new FakeSkillTools);
        FakeOpenAi::fake([FakeOpenAi::text('Done.')]);
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        try {
            app(SkillRunner::class)->run(User::factory()->create(), $skill, ['routine' => 'news_roundup']);
            $this->fail('A run without its required tool was started.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('spark__create-flint-digest', $e->getMessage());
        }

        $this->assertCount(0, FakeOpenAi::requests());
    }

    #[Test]
    public function a_dry_run_declines_and_records_writes_but_lets_reads_through(): void
    {
        $tools = $this->fakeTools(['manage-flint-topic' => '{"topics":[]}']);
        FakeOpenAi::fake([
            FakeOpenAi::toolCalls([
                ['spark__manage-flint-topic', ['operation' => 'list']],
                ['spark__create-flint-digest', ['title' => 'News — 10 Oct', 'blocks' => []]],
            ], ['input_tokens' => 10, 'output_tokens' => 2]),
            FakeOpenAi::text('Run notes.', ['input_tokens' => 15, 'output_tokens' => 5]),
        ]);
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        $result = app(SkillRunner::class)->run(User::factory()->create(), $skill, ['routine' => 'news_roundup'], dryRun: true);

        $this->assertSame([[
            'tool' => 'spark__create-flint-digest',
            'arguments' => ['title' => 'News — 10 Oct', 'blocks' => []],
        ]], $result->capturedWrites);
        $this->assertSame(['spark__manage-flint-topic'], $tools->calledTools());
        $this->assertSame('Run notes.', $result->text);
        $this->assertSame(25, $result->inputTokens);
        $this->assertArrayNotHasKey('captured_writes', $result->toArray());
    }

    #[Test]
    public function a_normal_run_never_declines_a_write(): void
    {
        $tools = $this->fakeTools();
        FakeOpenAi::fake([FakeOpenAi::toolCalls([['spark__create-flint-digest', []]]), FakeOpenAi::text('Done.')]);
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        $result = app(SkillRunner::class)->run(User::factory()->create(), $skill, ['routine' => 'news_roundup']);

        $this->assertSame([], $result->capturedWrites);
        $this->assertSame(['spark__create-flint-digest'], $tools->calledTools());
    }

    #[Test]
    public function spark_tools_run_in_process_as_the_user_with_a_scoped_token(): void
    {
        $actor = null;
        $this->app->instance(SkillToolSource::class, new FakeSkillTools(
            spark: ['create-flint-digest' => function () use (&$actor): string {
                $actor = Auth::user();

                return json_encode(['event_id' => 'evt-created']);
            }],
            realSpark: true,
        ));
        FakeOpenAi::fake([
            FakeOpenAi::toolCalls([['spark__get-flint-notes', []]]),
            FakeOpenAi::toolCalls([['spark__create-flint-digest', []]]),
            FakeOpenAi::text('Done.'),
        ]);
        $user = User::factory()->create();
        $skill = app(SkillRegistry::class)->get('flint-news-roundup');

        $result = app(SkillRunner::class)->run($user, $skill, ['routine' => 'news_roundup']);

        $this->assertSame(['spark__get-flint-notes', 'spark__create-flint-digest'], $result->toolsCalled);
        $notes = collect(FakeOpenAi::requests()[1]['input'])->firstWhere('type', 'function_call_output')['output'];
        $this->assertStringNotContainsString('error', strtolower($notes));
        $this->assertSame($user->id, $actor->id);
        $this->assertTrue($actor->tokenCan('flint:write'));
        $this->assertFalse($actor->tokenCan('tokens:manage'));
        $this->assertFalse($actor->tokenCan('integrations:manage'));
        $this->assertNull($user->currentAccessToken(), 'The scoped token leaked onto the caller\'s user.');
        $this->assertFalse(Auth::check(), 'The run left the user authenticated.');
    }

    /**
     * Fakes every Spark tool the tests call; create-flint-digest answers with
     * the digest event it created.
     *
     * @param  array<string, string>  $spark
     * @param  array<string, array<string, string>>  $remote
     */
    private function fakeTools(array $spark = [], array $remote = []): FakeSkillTools
    {
        $tools = new FakeSkillTools(
            spark: $spark + [
                'create-flint-digest' => json_encode(['content' => [['type' => 'text', 'text' => json_encode(['event_id' => 'evt-created'])]]]),
                'manage-flint-topic' => '{}',
                'get-events-by-filter-tool' => '{}',
                'get-latest-flint-digest' => '{}',
                'complete-flint-run' => '{}',
            ],
            remote: $remote + [self::CRONXTOOLS => ['docs__fetch' => '{}']],
        );
        $this->app->instance(SkillToolSource::class, $tools);

        return $tools;
    }
}
