<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\AiModel;
use App\Services\Ai\SkillDefinition;
use App\Services\Ai\SkillRegistry;
use App\Services\Ai\SkillToolLinter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SkillToolLinterTest extends TestCase
{
    #[Test]
    public function it_accepts_a_call_that_matches_the_tools_real_signature(): void
    {
        $problems = $this->lint(
            ['spark__get-events-by-filter-tool'],
            'spark__get-events-by-filter-tool(service: "newsletter", from_date: "yesterday", to_date: "today")',
        );

        $this->assertSame([], $problems);
    }

    /**
     * The regression this command exists for: flint-news-roundup asked for a
     * `domain` the tool has never accepted, and went two days reporting empty
     * source coverage over six newsletters a day.
     */
    #[Test]
    public function it_rejects_an_argument_the_tool_does_not_accept(): void
    {
        $problems = $this->lint(
            ['spark__get-events-by-filter-tool'],
            'spark__get-events-by-filter-tool(domain: "knowledge", from: "a", to: "b")',
        );

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('domain, from, to', $problems[0]);
        $this->assertStringContainsString('service', $problems[0]);
    }

    #[Test]
    public function it_reads_a_call_broken_across_lines(): void
    {
        $problems = $this->lint(['spark__manage-flint-topic'], <<<'MD'
            spark__manage-flint-topic(
              operation: "update",
              id: "<topic id>",
              nonsense: "x"
            )
            MD);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('nonsense', $problems[0]);
    }

    #[Test]
    public function it_rejects_a_tool_the_skill_does_not_declare(): void
    {
        $problems = $this->lint(
            ['spark__create-flint-digest'],
            'Load it with spark__get-day-summary-tool(dates: ["today"]) first.',
        );

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('does not declare it in allowed_tools', $problems[0]);
    }

    #[Test]
    public function it_rejects_a_tool_that_is_not_approved_for_skills(): void
    {
        $problems = $this->lint(
            ['spark__create-flint-digest'],
            'Then call supabase__execute_sql(query: "select 1").',
        );

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('not an approved tool', $problems[0]);
    }

    /**
     * Only the Spark namespace can be introspected in this process; the rest
     * are remote MCP servers, so their arguments are taken on trust.
     */
    #[Test]
    public function it_does_not_guess_at_arguments_for_a_remote_namespace(): void
    {
        $problems = $this->lint(
            ['karakeep__search-bookmarks'],
            'karakeep__search-bookmarks(query: "trains", whatever: 1)',
        );

        $this->assertSame([], $problems);
    }

    #[Test]
    public function every_vendored_skill_calls_tools_that_exist(): void
    {
        $linter = app(SkillToolLinter::class);

        foreach (app(SkillRegistry::class)->all() as $skill) {
            $this->assertSame([], $linter->lint($skill), "{$skill->name} has bad tool calls");
        }
    }

    /**
     * @param  array<int, string>  $allowedTools
     * @return array<int, string>
     */
    private function lint(array $allowedTools, string $body): array
    {
        return app(SkillToolLinter::class)->lint(new SkillDefinition(
            name: 'test-skill',
            description: 'A skill under test.',
            model: AiModel::Reasoning,
            allowedTools: $allowedTools,
            requiredSuccessTools: [],
            maxToolCalls: 40,
            timeoutSeconds: 300,
            body: $body,
        ));
    }
}
