<?php

namespace Tests\Feature\Flint;

use App\Integrations\PluginRegistry;
use App\Jobs\Flint\GenerateArticlesWaitingJob;
use App\Models\Block;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\User;
use App\Services\AssistantPromptingService;
use App\Services\FlintBlockCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class NewspaperGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $flintIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Create Flint integration for the user
        $this->flintIntegration = Integration::create([
            'user_id' => $this->user->id,
            'service' => 'flint',
            'instance_type' => 'digest',
            'name' => 'Flint Digest',
            'enabled' => true,
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
    public function generate_articles_waiting_creates_block_with_pitches(): void
    {
        // Mock the prompting service
        $mockPrompting = Mockery::mock(AssistantPromptingService::class);
        $mockPrompting->shouldReceive('generateResponse')
            ->andReturn('{"pitch": "This article reveals surprising insights about productivity.", "reading_time": "5 min", "key_points": ["Point 1", "Point 2"]}');

        $this->app->instance(AssistantPromptingService::class, $mockPrompting);

        // Create a one-time bookmark with content
        EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => 'Test Article',
            'url' => 'https://example.com/article',
            'time' => now(),
            'content' => 'This is the article content that was fetched and extracted.',
            'metadata' => [
                'fetch_mode' => 'once',
                'domain' => 'example.com',
                'summary_short' => 'A brief summary of the article',
            ],
        ]);

        $job = new GenerateArticlesWaitingJob($this->user);
        $job->handle(
            $mockPrompting,
            app(FlintBlockCreationService::class)
        );

        // Check that a block was created
        $block = Block::where('block_type', 'flint_articles_waiting')
            ->whereHas('event', function ($q) {
                $q->where('service', 'flint');
            })
            ->first();

        $this->assertNotNull($block);
        $this->assertEquals('Articles Waiting', $block->title);
        $this->assertNotEmpty($block->metadata['articles']);
        $this->assertNotEmpty($block->metadata['articles'][0]['pitch']);
    }

    /**
     * @test
     */
    public function generate_articles_waiting_skips_read_articles(): void
    {
        $mockPrompting = Mockery::mock(AssistantPromptingService::class);
        $mockPrompting->shouldReceive('generateResponse')->never();

        $this->app->instance(AssistantPromptingService::class, $mockPrompting);

        // Create a one-time bookmark that has been read
        EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => 'Read Article',
            'url' => 'https://example.com/read-article',
            'time' => now(),
            'content' => 'Article content',
            'metadata' => [
                'fetch_mode' => 'once',
                'domain' => 'example.com',
                'read_at' => now()->toIso8601String(),
            ],
        ]);

        $job = new GenerateArticlesWaitingJob($this->user);
        $job->handle(
            $mockPrompting,
            app(FlintBlockCreationService::class)
        );

        // No block should be created since there are no unread articles
        $block = Block::where('block_type', 'flint_articles_waiting')->first();
        $this->assertNull($block);
    }

    /**
     * @test
     */
    public function generate_articles_waiting_skips_recurring_fetches(): void
    {
        $mockPrompting = Mockery::mock(AssistantPromptingService::class);
        $mockPrompting->shouldReceive('generateResponse')->never();

        $this->app->instance(AssistantPromptingService::class, $mockPrompting);

        // Create a recurring fetch (not one-time bookmark)
        EventObject::create([
            'user_id' => $this->user->id,
            'concept' => 'bookmark',
            'type' => 'fetch_webpage',
            'title' => 'Recurring Feed',
            'url' => 'https://example.com/feed',
            'time' => now(),
            'content' => 'Feed content',
            'metadata' => [
                'fetch_mode' => 'recurring',
                'domain' => 'example.com',
            ],
        ]);

        $job = new GenerateArticlesWaitingJob($this->user);
        $job->handle(
            $mockPrompting,
            app(FlintBlockCreationService::class)
        );

        $block = Block::where('block_type', 'flint_articles_waiting')->first();
        $this->assertNull($block);
    }

    /**
     * @test
     */
    public function news_briefing_block_types_registered_in_plugin(): void
    {
        $flintPlugin = PluginRegistry::getPluginInstance('flint');

        $blockTypes = $flintPlugin->getBlockTypes();

        $this->assertArrayHasKey('flint_news_briefing', $blockTypes);
        $this->assertArrayHasKey('flint_articles_waiting', $blockTypes);
        $this->assertArrayHasKey('flint_coaching_check_in', $blockTypes);
        $this->assertArrayHasKey('flint_coaching_insight', $blockTypes);
    }

    /**
     * @test
     */
    public function block_creation_service_creates_news_briefing_block(): void
    {
        $blockCreation = app(FlintBlockCreationService::class);

        $flintEvent = $blockCreation->getOrCreateFlintEvent($this->user);

        $briefingData = [
            'title' => 'Today\'s Tech News',
            'summary' => 'Key developments in the tech industry.',
            'sources' => ['TechCrunch', 'The Verge'],
            'key_stories' => [
                [
                    'headline' => 'AI Breakthrough',
                    'summary' => 'New AI model announced.',
                    'importance' => 'high',
                ],
            ],
            'themes' => [
                ['theme' => 'Artificial Intelligence'],
            ],
        ];

        $block = $blockCreation->createNewsBriefingBlock($this->user, $briefingData, $flintEvent);

        $this->assertInstanceOf(Block::class, $block);
        $this->assertEquals('flint_news_briefing', $block->block_type);
        $this->assertEquals('Today\'s Tech News', $block->title);
        $this->assertEquals(2, $block->value); // 2 sources
        $this->assertCount(1, $block->metadata['key_stories']);
    }

    /**
     * @test
     */
    public function block_creation_service_creates_articles_waiting_block(): void
    {
        $blockCreation = app(FlintBlockCreationService::class);

        $flintEvent = $blockCreation->getOrCreateFlintEvent($this->user);

        $articlesData = [
            'title' => 'Articles Waiting',
            'articles' => [
                [
                    'id' => 'article-1',
                    'title' => 'Test Article',
                    'url' => 'https://example.com/article',
                    'domain' => 'example.com',
                    'pitch' => 'Read this to learn something new.',
                ],
            ],
            'total_unread' => 5,
        ];

        $block = $blockCreation->createArticlesWaitingBlock($this->user, $articlesData, $flintEvent);

        $this->assertInstanceOf(Block::class, $block);
        $this->assertEquals('flint_articles_waiting', $block->block_type);
        $this->assertEquals(1, $block->value); // 1 article
        $this->assertEquals(5, $block->metadata['total_unread']);
    }

    /**
     * @test
     */
    public function block_creation_service_creates_coaching_check_in_block(): void
    {
        $blockCreation = app(FlintBlockCreationService::class);

        $flintEvent = $blockCreation->getOrCreateFlintEvent($this->user);

        $checkInData = [
            'title' => 'Sleep Score Check-In',
            'coaching_session_id' => 'session-123',
            'anomaly_context' => [
                'metric_name' => 'Sleep Score',
                'deviation_percent' => 25,
            ],
            'questions' => ['Question 1?', 'Question 2?'],
            'pattern_suggestions' => [],
        ];

        $block = $blockCreation->createCoachingCheckInBlock($this->user, $checkInData, $flintEvent);

        $this->assertInstanceOf(Block::class, $block);
        $this->assertEquals('flint_coaching_check_in', $block->block_type);
        $this->assertEquals('Sleep Score Check-In', $block->title);
        $this->assertEquals('session-123', $block->metadata['coaching_session_id']);
    }

    /**
     * @test
     */
    public function block_creation_service_creates_coaching_insight_block(): void
    {
        $blockCreation = app(FlintBlockCreationService::class);

        $flintEvent = $blockCreation->getOrCreateFlintEvent($this->user);

        $insightData = [
            'title' => 'Late Nights Affect Sleep',
            'learned_pattern_id' => 'pattern-123',
            'insight' => 'Working late consistently lowers sleep quality.',
            'trigger_conditions' => ['activity' => 'working late'],
            'consequences' => ['metric' => 'sleep_score'],
            'confirmation_count' => 3,
            'confidence' => 0.75,
        ];

        $block = $blockCreation->createCoachingInsightBlock($this->user, $insightData, $flintEvent);

        $this->assertInstanceOf(Block::class, $block);
        $this->assertEquals('flint_coaching_insight', $block->block_type);
        $this->assertEquals(75, $block->value); // 0.75 * 100
        $this->assertEquals('pattern-123', $block->metadata['learned_pattern_id']);
    }
}
