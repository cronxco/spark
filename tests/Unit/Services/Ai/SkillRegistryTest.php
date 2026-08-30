<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\AiModel;
use App\Services\Ai\SkillRegistry;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class SkillRegistryTest extends TestCase
{
    private function writeSkill(string $name, string $frontmatter, string $body = "Do the thing."): string
    {
        $dir = resource_path('ai/skills');
        $path = "{$dir}/{$name}.md";
        File::put($path, "---\n{$frontmatter}\n---\n\n{$body}\n");

        return $path;
    }

    #[Test]
    public function it_loads_the_four_vendored_async_skills(): void
    {
        $skills = (new SkillRegistry)->all();

        foreach (['spark-day-briefing-async', 'flint-topics', 'flint-reading-list', 'flint-news-roundup'] as $name) {
            $this->assertArrayHasKey($name, $skills);
            $this->assertNotSame('', $skills[$name]->body);
            $this->assertNotEmpty($skills[$name]->allowedTools);
            $this->assertSame(AiModel::Reasoning, $skills[$name]->model);
        }
    }

    #[Test]
    public function the_conversational_briefing_skill_is_deliberately_not_vendored(): void
    {
        $this->assertFalse(
            (new SkillRegistry)->has('spark-day-briefing'),
            'spark-day-briefing expects a back-and-forth check-in and cannot run unattended'
        );
    }

    #[Test]
    public function no_vendored_skill_can_reach_an_infrastructure_namespace(): void
    {
        foreach ((new SkillRegistry)->all() as $skill) {
            foreach ($skill->allowedTools as $tool) {
                $namespace = strtok($tool, '__');

                $this->assertNotContains(
                    $namespace,
                    SkillRegistry::FORBIDDEN_NAMESPACES,
                    "Skill {$skill->name} may reach {$tool}"
                );
            }
        }
    }

    #[Test]
    public function a_skill_declaring_an_infrastructure_tool_refuses_to_load(): void
    {
        $path = $this->writeSkill('temp-danger', "name: temp-danger\nmodel: reasoning\nallowed_tools:\n  - supabase__execute_sql");

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage("declares the forbidden tool 'supabase__execute_sql'");

            (new SkillRegistry)->all();
        } finally {
            File::delete($path);
        }
    }

    #[Test]
    public function a_skill_with_no_tools_refuses_to_load(): void
    {
        $path = $this->writeSkill('temp-toolless', "name: temp-toolless\nmodel: reasoning\nallowed_tools: []");

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('must declare at least one allowed tool');

            (new SkillRegistry)->all();
        } finally {
            File::delete($path);
        }
    }

    #[Test]
    public function a_skill_with_an_unrecognised_namespace_refuses_to_load(): void
    {
        $path = $this->writeSkill('temp-unknown', "name: temp-unknown\nmodel: reasoning\nallowed_tools:\n  - github__delete_repo");

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage("unrecognised tool namespace 'github'");

            (new SkillRegistry)->all();
        } finally {
            File::delete($path);
        }
    }

    #[Test]
    public function an_unknown_skill_name_fails_loudly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown Flint skill: not-a-skill');

        (new SkillRegistry)->get('not-a-skill');
    }
}
