<?php

namespace Tests\Unit\Services\Ai\Knowledge;

use App\Services\Ai\Knowledge\SummaryGenerator;
use App\Services\Ai\PromptRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SummaryGeneratorTest extends TestCase
{
    private function generator(): SummaryGenerator
    {
        return new SummaryGenerator(new PromptRepository);
    }

    #[Test]
    public function it_repairs_missing_summary_tweet_from_summary_short(): void
    {
        $summaries = $this->generator()->normalise([
            'summary_short' => str_repeat('a', 300),
            'summary_paragraph' => 'Paragraph',
            'key_takeaways' => ['One'],
            'tldr' => 'TLDR',
            'emoji' => 'x',
            'tags' => [],
        ]);

        $this->assertArrayHasKey('summary_tweet', $summaries);
        $this->assertSame(280, mb_strlen($summaries['summary_tweet']));
    }

    #[Test]
    public function it_falls_back_to_tldr_when_there_is_no_short_summary(): void
    {
        $summaries = $this->generator()->normalise([
            'tldr' => 'A very short summary',
            'emoji' => 'x',
            'tags' => [],
        ]);

        $this->assertSame('A very short summary', $summaries['summary_tweet']);
    }

    #[Test]
    public function it_leaves_an_existing_summary_tweet_alone(): void
    {
        $summaries = $this->generator()->normalise([
            'summary_tweet' => 'Already here',
            'summary_short' => 'Something else',
        ]);

        $this->assertSame('Already here', $summaries['summary_tweet']);
    }

    #[Test]
    public function it_cannot_repair_a_response_with_nothing_to_repair_from(): void
    {
        $summaries = $this->generator()->normalise(['emoji' => 'x']);

        $this->assertArrayNotHasKey('summary_tweet', $summaries);
    }
}
