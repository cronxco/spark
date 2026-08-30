<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\PromptRepository;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class PromptRepositoryTest extends TestCase
{
    #[Test]
    public function it_loads_every_knowledge_prompt_the_pipeline_depends_on(): void
    {
        $repository = new PromptRepository;

        foreach (['knowledge/extract-article', 'knowledge/extract-newsletter', 'knowledge/generate-summaries'] as $key) {
            $this->assertNotSame('', trim($repository->get($key)), "Prompt {$key} is empty");
        }
    }

    #[Test]
    public function the_summary_prompt_still_asks_for_every_required_key(): void
    {
        $prompt = (new PromptRepository)->get('knowledge/generate-summaries');

        foreach (\App\Services\Ai\Knowledge\SummaryGenerator::REQUIRED_KEYS as $key) {
            $this->assertStringContainsString($key, $prompt, "Prompt no longer mentions {$key}");
        }
    }

    #[Test]
    public function a_missing_prompt_fails_loudly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AI prompt not found: knowledge/does-not-exist');

        (new PromptRepository)->get('knowledge/does-not-exist');
    }
}
