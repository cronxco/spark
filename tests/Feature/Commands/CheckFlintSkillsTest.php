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
