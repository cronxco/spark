<?php

namespace Tests\Unit\Jobs\Fetch;

use App\Jobs\Data\Fetch\GenerateSummariesJob;
use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class GenerateSummariesJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_repairs_missing_summary_tweet_from_summary_short(): void
    {
        $integration = Integration::factory()->create(['service' => 'fetch']);
        $webpage = EventObject::factory()->create([
            'user_id' => $integration->user_id,
            'type' => 'fetch_webpage',
        ]);

        $job = new GenerateSummariesJob($integration, null, $webpage, [], 'Article text');

        $reflection = new ReflectionClass($job);
        $method = $reflection->getMethod('normaliseSummaries');
        $method->setAccessible(true);

        $summaries = $method->invoke($job, [
            'summary_short' => str_repeat('a', 300),
            'summary_paragraph' => 'Paragraph',
            'key_takeaways' => ['One'],
            'tldr' => 'TLDR',
            'emoji' => 'x',
            'tags' => [],
        ]);

        $this->assertArrayHasKey('summary_tweet', $summaries);
        $this->assertSame(280, strlen($summaries['summary_tweet']));
    }
}
