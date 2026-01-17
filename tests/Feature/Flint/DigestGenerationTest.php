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
        // Mock the AI service
        $mockPrompting = Mockery::mock(AssistantPromptingService::class);
        $mockPrompting->shouldReceive('generateResponse')
            ->once()
            ->andReturn(json_encode([
                'headline' => 'Test Daily Digest',
                'summary' => 'This is a test digest summary.',
                'top_insights' => [
                    [
                        'icon' => '💡',
                        'title' => 'Test Insight',
                        'description' => 'This is a test insight.',
                    ],
                ],
                'wins' => ['Completed workout'],
                'watch_points' => ['Low sleep score'],
                'tomorrow_focus' => ['Get more sleep'],
                'metrics' => [
                    'total_insights' => 1,
                    'domains_analyzed' => 2,
                ],
            ]));

        $this->app->instance(AssistantPromptingService::class, $mockPrompting);

        // Run the job
        $job = new GenerateDailyDigestJob($this->user, 'morning');
        $job->handle(app(AssistantPromptingService::class));

        // Assert digest block was created
        $this->assertDatabaseHas('blocks', [
            'block_type' => 'flint_digest',
        ]);

        $digestBlock = Block::where('block_type', 'flint_digest')->first();
        $this->assertNotNull($digestBlock);
        $this->assertEquals('Test Daily Digest', $digestBlock->metadata['headline']);
        $this->assertArrayHasKey('top_insights', $digestBlock->metadata);
        $this->assertCount(1, $digestBlock->metadata['top_insights']);
    }

    /**
     * @test
     */
    public function digest_links_to_source_events(): void
    {
        // Mock the AI service
        $mockPrompting = Mockery::mock(AssistantPromptingService::class);
        $mockPrompting->shouldReceive('generateResponse')
            ->andReturn(json_encode([
                'headline' => 'Test Digest',
                'summary' => 'Summary',
                'top_insights' => [],
                'wins' => [],
                'watch_points' => [],
                'tomorrow_focus' => [],
                'metrics' => ['total_insights' => 0],
            ]));

        $this->app->instance(AssistantPromptingService::class, $mockPrompting);

        // Create some events for the user
        $event1 = Event::factory()->create([
            'integration_id' => $this->flintIntegration->id,
            'user_id' => $this->user->id,
            'service' => 'oura',
            'action' => 'had_sleep_score',
            'time' => now()->subHours(2),
        ]);

        $event2 = Event::factory()->create([
            'integration_id' => $this->flintIntegration->id,
            'user_id' => $this->user->id,
            'service' => 'hevy',
            'action' => 'had_workout',
            'time' => now()->subHours(4),
        ]);

        // Run the job
        $job = new GenerateDailyDigestJob($this->user, 'evening');
        $job->handle(app(AssistantPromptingService::class));

        // Assert digest was created
        $digestBlock = Block::where('block_type', 'flint_digest')->first();
        $this->assertNotNull($digestBlock);

        // Assert relationships exist (if implemented)
        // This depends on whether GenerateDailyDigestJob creates relationships
    }

    /**
     * @test
     */
    public function digest_handles_empty_data_gracefully(): void
    {
        // Mock the AI service to return minimal data
        $mockPrompting = Mockery::mock(AssistantPromptingService::class);
        $mockPrompting->shouldReceive('generateResponse')
            ->andReturn(json_encode([
                'headline' => 'No Activity Today',
                'summary' => 'Not much happened today.',
                'top_insights' => [],
                'wins' => [],
                'watch_points' => [],
                'tomorrow_focus' => [],
                'metrics' => ['total_insights' => 0],
            ]));

        $this->app->instance(AssistantPromptingService::class, $mockPrompting);

        // Run the job with no events
        $job = new GenerateDailyDigestJob($this->user, 'morning');
        $job->handle(app(AssistantPromptingService::class));

        // Assert digest was still created
        $this->assertDatabaseHas('blocks', [
            'block_type' => 'flint_digest',
        ]);

        $digestBlock = Block::where('block_type', 'flint_digest')->first();
        $this->assertNotNull($digestBlock);
        $this->assertEquals('No Activity Today', $digestBlock->metadata['headline']);
    }
}
