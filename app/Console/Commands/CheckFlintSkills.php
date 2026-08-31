<?php

namespace App\Console\Commands;

use App\Services\Ai\SkillRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Guards against the vendored skills drifting from the copies the Claude Code
 * Routines run. Spark is the source of truth for the prompt body; the manifest
 * keys it adds are ignored by Claude Code, so one file serves both runtimes.
 */
class CheckFlintSkills extends Command
{
    protected $signature = 'flint:skills-check
                            {--claude-repo= : Path to a cronxco/claude checkout (default: sibling ../claude)}
                            {--allow-missing : Permit a missing Claude checkout for explicit local-only work}';

    protected $description = 'Verify the vendored Flint skills still match the Claude Code Routine copies';

    public function handle(SkillRegistry $registry): int
    {
        $repo = $this->option('claude-repo') ?: base_path('../claude');

        if (! File::isDirectory($repo . '/.claude/skills')) {
            $message = "No cronxco/claude checkout at {$repo}.";
            if (! $this->option('allow-missing')) {
                $this->error($message . ' Pass --allow-missing only for an explicit local-only check.');

                return Command::FAILURE;
            }

            $this->warn($message . ' Drift check explicitly skipped.');

            return Command::SUCCESS;
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

        return Command::SUCCESS;
    }

    private function normalise(string $contents): string
    {
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        $lines = array_map('rtrim', explode("\n", $contents));

        return trim(implode("\n", $lines)) . "\n";
    }
}
