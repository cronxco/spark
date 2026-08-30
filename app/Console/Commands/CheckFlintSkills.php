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
                            {--claude-repo= : Path to a cronxco/claude checkout (default: sibling ../claude)}';

    protected $description = 'Verify the vendored Flint skills still match the Claude Code Routine copies';

    public function handle(SkillRegistry $registry): int
    {
        $repo = $this->option('claude-repo') ?: base_path('../claude');

        if (! File::isDirectory($repo . '/.claude/skills')) {
            $this->warn("No cronxco/claude checkout at {$repo}; skipping drift check.");

            return Command::SUCCESS;
        }

        $drifted = [];

        foreach ($registry->all() as $skill) {
            $theirs = "{$repo}/.claude/skills/{$skill->name}/SKILL.md";

            if (! File::exists($theirs)) {
                $drifted[] = "{$skill->name}: missing from the Claude Code skills directory";

                continue;
            }

            if ($this->body(File::get($theirs)) !== $skill->body) {
                $drifted[] = "{$skill->name}: prompt body differs";
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

    /**
     * The prose after the frontmatter, which is the part that must match.
     */
    private function body(string $contents): string
    {
        if (preg_match("/\A---\n.*?\n---\n(.*)\z/s", $contents, $matches)) {
            return trim($matches[1]);
        }

        return trim($contents);
    }
}
