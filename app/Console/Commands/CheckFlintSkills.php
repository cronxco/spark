<?php

namespace App\Console\Commands;

use App\Services\Ai\SkillRegistry;
use App\Services\Ai\SkillToolLinter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Guards the vendored skills two ways.
 *
 * First, against drifting from the copies the Claude Code Routines run: Spark
 * is the source of truth for the prompt body, and the manifest keys it adds are
 * ignored by Claude Code, so one file serves both runtimes.
 *
 * Second, against the prose calling tools that do not exist as written. Both
 * copies being byte-identical says nothing about either being correct — the
 * `domain:` argument that left flint-news-roundup reporting empty coverage over
 * six newsletters a day was identical in both places and passed this command.
 * See {@see SkillToolLinter}.
 */
class CheckFlintSkills extends Command
{
    protected $signature = 'flint:skills-check
                            {--claude-repo= : Path to a cronxco/claude checkout (default: sibling ../claude)}
                            {--allow-missing : Permit a missing Claude checkout for explicit local-only work}
                            {--skip-tools : Skip the tool-call check and only compare the two copies}';

    protected $description = 'Verify the vendored Flint skills match the Claude Code copies and call tools that exist';

    public function handle(SkillRegistry $registry, SkillToolLinter $linter): int
    {
        $failed = $this->option('skip-tools') ? false : $this->checkTools($registry, $linter);
        $repo = $this->option('claude-repo') ?: base_path('../claude');

        if (! File::isDirectory($repo . '/.claude/skills')) {
            $message = "No cronxco/claude checkout at {$repo}.";
            if (! $this->option('allow-missing')) {
                $this->error($message . ' Pass --allow-missing only for an explicit local-only check.');

                return Command::FAILURE;
            }

            $this->warn($message . ' Drift check explicitly skipped.');

            return $failed ? Command::FAILURE : Command::SUCCESS;
        }

        $drifted = [];

        foreach ($registry->all() as $skill) {
            $theirs = "{$repo}/.claude/skills/{$skill->name}/SKILL.md";

            if (! File::exists($theirs)) {
                $drifted[] = "{$skill->name}: missing from the Claude Code skills directory";

                continue;
            }

            $ours = resource_path("ai/skills/{$skill->name}/SKILL.md");
            if ($this->normalise(File::get($theirs)) !== $this->normalise(File::get($ours))) {
                $drifted[] = "{$skill->name}: complete skill file differs";
            }
        }

        if ($drifted !== []) {
            $this->error('Vendored Flint skills have drifted:');
            foreach ($drifted as $line) {
                $this->line("  - {$line}");
            }

            return Command::FAILURE;
        }

        $this->info('All ' . count($registry->all()) . ' vendored Flint skills match the Claude Code copies.');

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Lint every skill's tool calls, reporting all problems rather than
     * stopping at the first: a skill written against an imagined API usually
     * gets more than one call wrong.
     */
    private function checkTools(SkillRegistry $registry, SkillToolLinter $linter): bool
    {
        $failed = false;

        foreach ($registry->all() as $skill) {
            $problems = $linter->lint($skill);

            if ($problems === []) {
                continue;
            }

            $failed = true;
            $this->error("{$skill->name} calls tools that do not exist as written:");

            foreach ($problems as $problem) {
                $this->line("  - {$problem}");
            }
        }

        if (! $failed) {
            $this->info('All ' . count($registry->all()) . ' vendored Flint skills call tools that exist.');
        }

        return $failed;
    }

    private function normalise(string $contents): string
    {
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        $lines = array_map('rtrim', explode("\n", $contents));

        return trim(implode("\n", $lines)) . "\n";
    }
}
