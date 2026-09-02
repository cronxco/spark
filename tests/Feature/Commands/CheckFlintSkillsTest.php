<?php

namespace Tests\Feature\Commands;

use App\Services\Ai\SkillRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CheckFlintSkillsTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = storage_path('framework/testing/claude-' . uniqid());
        File::ensureDirectoryExists($this->repo . '/.claude/skills');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repo);

        parent::tearDown();
    }

    #[Test]
    public function it_passes_when_every_vendored_skill_matches_its_claude_copy(): void
    {
        $this->mirrorAllSkills();

        $this->artisan('flint:skills-check', ['--claude-repo' => $this->repo])
            ->expectsOutputToContain('vendored Flint skills match')
            ->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function it_fails_when_a_claude_copy_has_drifted(): void
    {
        $this->mirrorAllSkills();
        File::append($this->repo . '/.claude/skills/flint-topics/SKILL.md', "\ndrift\n");

        $this->artisan('flint:skills-check', ['--claude-repo' => $this->repo])
            ->expectsOutputToContain('flint-topics: complete skill file differs')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function it_fails_when_a_skill_is_missing_from_the_claude_checkout(): void
    {
        $this->mirrorAllSkills();
        File::deleteDirectory($this->repo . '/.claude/skills/flint-topics');

        $this->artisan('flint:skills-check', ['--claude-repo' => $this->repo])
            ->expectsOutputToContain('flint-topics: missing from the Claude Code skills directory')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function a_missing_checkout_fails_unless_explicitly_allowed(): void
    {
        $missing = $this->repo . '/nope';

        $this->artisan('flint:skills-check', ['--claude-repo' => $missing])
            ->assertExitCode(Command::FAILURE);

        $this->artisan('flint:skills-check', ['--claude-repo' => $missing, '--allow-missing' => true])
            ->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function it_reports_that_the_skills_call_tools_that_exist(): void
    {
        $this->mirrorAllSkills();

        $this->artisan('flint:skills-check', ['--claude-repo' => $this->repo])
            ->expectsOutputToContain('vendored Flint skills call tools that exist')
            ->assertExitCode(Command::SUCCESS);
    }

    /**
     * The two copies being byte-identical says nothing about either being
     * correct: the `domain:` argument that left flint-news-roundup reporting
     * empty coverage over six newsletters a day was identical in both places.
     */
    #[Test]
    public function it_fails_when_a_skill_calls_a_tool_with_an_argument_it_does_not_accept(): void
    {
        $vendored = resource_path('ai/skills/flint-topics/SKILL.md');
        $original = File::get($vendored);

        File::put($vendored, str_replace(
            'from_date: "<local_date - 6d>"',
            'domain: "knowledge"',
            $original,
        ));
        $this->mirrorAllSkills();

        try {
            $this->artisan('flint:skills-check', ['--claude-repo' => $this->repo])
                ->expectsOutputToContain('flint-topics calls tools that do not exist as written')
                ->expectsOutputToContain('unknown argument domain')
                ->assertExitCode(Command::FAILURE);
        } finally {
            File::put($vendored, $original);
        }
    }

    #[Test]
    public function the_tool_check_can_be_skipped(): void
    {
        $vendored = resource_path('ai/skills/flint-topics/SKILL.md');
        $original = File::get($vendored);

        File::put($vendored, str_replace('from_date: "<local_date - 6d>"', 'domain: "knowledge"', $original));
        $this->mirrorAllSkills();

        try {
            $this->artisan('flint:skills-check', ['--claude-repo' => $this->repo, '--skip-tools' => true])
                ->assertExitCode(Command::SUCCESS);
        } finally {
            File::put($vendored, $original);
        }
    }

    private function mirrorAllSkills(): void
    {
        foreach (app(SkillRegistry::class)->all() as $skill) {
            $target = "{$this->repo}/.claude/skills/{$skill->name}";
            File::ensureDirectoryExists($target);
            File::copy(
                resource_path("ai/skills/{$skill->name}/SKILL.md"),
                "{$target}/SKILL.md",
            );
        }
    }
}
