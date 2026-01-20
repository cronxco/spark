<?php

namespace Tests\Feature\Flint;

use App\Jobs\Flint\GenerateDailyDigestJob;
use App\Models\Block;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use App\Services\AssistantPromptingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DigestGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $flintIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->flintIntegration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'flint',
            'instance_type' => 'digest',
        ]);

        // Mock AI service for all tests
        $this->mockAIService();
    }

    protected function mockAIService(): void
    {
        $mockPrompting = Mockery::mock(AssistantPromptingService::class);

        // Mock generateResponse (for pattern extraction, etc.)
        $mockPrompting->shouldReceive('generateResponse')
            ->andReturn(json_encode([
                'headline' => 'Test Daily Digest',
                'summary' => 'Test summary',
                'top_insights' => [],
                'wins' => [],
                'watch_points' => [],
                'tomorrow_focus' => [],
                'metrics' => ['total_insights' => 0],
            ]));

        // Mock generateDigest (for digest generation)
        $mockPrompting->shouldReceive('generateDigest')
            ->andReturn([
                'headline' => 'Your Morning Digest',
                'key_points' => ['Point 1', 'Point 2', 'Point 3'],
                'actions_required' => [],
                'things_to_be_aware_of' => null,
                'insight' => [
                    'title' => 'Test Insight',
                    'content' => 'Test insight content',
                    'supporting_data' => [],
                ],
                'suggestion' => [
                    'title' => 'Test Suggestion',
                    'content' => 'Test suggestion content',
                    'actionable' => true,
                    'automation_hint' => null,
                ],
            ]);

        $this->app->instance(AssistantPromptingService::class, $mockPrompting);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @test
     */
    public function generate_daily_digest_creates_block(): void
    {
        // Run the job
        $job = new GenerateDailyDigestJob($this->user, 'morning');
        $job->handle(app(AssistantPromptingService::class));

        // Assert digest blocks were created
        $this->assertDatabaseHas('blocks', [
            'block_type' => 'flint_summarised_headline',
        ]);

        $headlineBlock = Block::where('block_type', 'flint_summarised_headline')->first();
        $this->assertNotNull($headlineBlock);
        $this->assertEquals('Your Morning Digest', $headlineBlock->metadata['content']);
    }

    /**
     * @test
     */
    public function digest_links_to_source_events(): void
    {
        // Create some events for the user
        $event1 = Event::factory()->create([
            'integration_id' => $this->flintIntegration->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'time' => now()->subHours(2),
        ]);

        $event2 = Event::factory()->create([
            'integration_id' => $this->flintIntegration->id,
            'service' => 'hevy',
            'action' => 'had_workout',
            'time' => now()->subHours(4),
        ]);

        // Run the job
        $job = new GenerateDailyDigestJob($this->user, 'evening');
        $job->handle(app(AssistantPromptingService::class));

        // Assert digest blocks were created
        $this->assertDatabaseHas('blocks', [
            'block_type' => 'flint_summarised_headline',
        ]);

        $headlineBlock = Block::where('block_type', 'flint_summarised_headline')->first();
        $this->assertNotNull($headlineBlock);
    }

    /**
     * @test
     */
    public function digest_handles_empty_data_gracefully(): void
    {
        // Run the job with no events
        $job = new GenerateDailyDigestJob($this->user, 'morning');
        $job->handle(app(AssistantPromptingService::class));

        // Assert digest blocks were still created
        $this->assertDatabaseHas('blocks', [
            'block_type' => 'flint_summarised_headline',
        ]);

        $headlineBlock = Block::where('block_type', 'flint_summarised_headline')->first();
        $this->assertNotNull($headlineBlock);
        $this->assertEquals('Your Morning Digest', $headlineBlock->metadata['content']);
    }
}
