<?php

namespace Tests\Unit\Integrations\Oura;

use App\Integrations\Oura\OuraPlugin;
use App\Integrations\Oura\Traits\HasOuraBlocks;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HasOuraBlocksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Integration $integration;
    private Event $event;
    private OuraPlugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $this->user->id]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
        ]);

        $target = EventObject::factory()->create(['user_id' => $this->user->id]);
        $actor = EventObject::factory()->create(['user_id' => $this->user->id]);

        $this->event = Event::factory()->create([
            'integration_id' => $this->integration->id,
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        $this->plugin = new OuraPlugin;
    }

    #[Test]
    public function it_creates_contributor_blocks_correctly(): void
    {
        $testClass = new class
        {
            use HasOuraBlocks;

            /**
             * @test
             */
            public function create_contributor_blocks($event, $contributors, $plugin)
            {
                $this->createContributorBlocks($event, $contributors, $plugin);
            }
        };

        $contributors = [
            'meet_daily_targets' => 75,
            'move_every_hour' => 90,
            'stay_active' => 80,
        ];

        $testClass->create_contributor_blocks($this->event, $contributors, $this->plugin);

        // Refresh to get the created blocks
        $this->event->refresh();
        $blocks = $this->event->blocks;

        $this->assertCount(3, $blocks);

        $targetBlock = $blocks->where('title', 'Meet Daily Targets')->first();
        $this->assertNotNull($targetBlock);
        $this->assertEquals('contributor', $targetBlock->block_type);
        $this->assertEquals(75, $targetBlock->value);
        $this->assertEquals('percent', $targetBlock->value_unit);
        $this->assertEquals('contributor', $targetBlock->metadata['type']);
        $this->assertEquals('meet_daily_targets', $targetBlock->metadata['field']);

        $moveBlock = $blocks->where('title', 'Move Every Hour')->first();
        $this->assertNotNull($moveBlock);
        $this->assertEquals(90, $moveBlock->value);
    }

    #[Test]
    public function it_creates_activity_metric_blocks_correctly(): void
    {
        $testClass = new class
        {
            use HasOuraBlocks;

            /**
             * @test
             */
            public function create_activity_metric_blocks($event, $item, $metrics, $plugin)
            {
                $this->createActivityMetricBlocks($event, $item, $metrics, $plugin);
            }
        };

        $item = [
            'steps' => 10432,
            'cal_total' => 2350,
            'non_wear_time' => 3600,
        ];

        $metrics = [
            'steps' => [
                'unit' => 'count',
                'title' => 'Steps',
                'type' => 'core_metric',
                'category' => 'movement',
            ],
            'cal_total' => [
                'unit' => 'kcal',
                'title' => 'Total Calories',
                'type' => 'core_metric',
                'category' => 'energy',
            ],
            'non_wear_time' => [
                'unit' => 'seconds',
                'title' => 'Non-Wear Time',
                'type' => 'time_metric',
                'category' => 'usage',
            ],
        ];

        $testClass->create_activity_metric_blocks($this->event, $item, $metrics, $this->plugin);

        $this->event->refresh();
        $blocks = $this->event->blocks;

        $this->assertCount(3, $blocks);

        $stepsBlock = $blocks->where('title', 'Steps')->first();
        $this->assertNotNull($stepsBlock);
        $this->assertEquals('activity_metric', $stepsBlock->block_type);
        $this->assertEquals(10432, $stepsBlock->value);
        $this->assertEquals('count', $stepsBlock->value_unit);
        $this->assertEquals('core_metric', $stepsBlock->metadata['type']);
        $this->assertEquals('movement', $stepsBlock->metadata['category']);

        $caloriesBlock = $blocks->where('title', 'Total Calories')->first();
        $this->assertNotNull($caloriesBlock);
        $this->assertEquals(2350, $caloriesBlock->value);
        $this->assertEquals('kcal', $caloriesBlock->value_unit);
    }

    #[Test]
    public function it_creates_sleep_stage_blocks_correctly(): void
    {
        $testClass = new class
        {
            use HasOuraBlocks;

            /**
             * @test
             */
            public function create_sleep_stage_blocks($event, $item, $metrics, $plugin)
            {
                $this->createSleepStageBlocks($event, $item, $metrics, $plugin);
            }
        };

        $item = [
            'total_sleep_duration' => 28800, // 8 hours in seconds
            'deep_sleep_duration' => 7200,   // 2 hours
            'rem_sleep_duration' => 5400,    // 1.5 hours
            'sleep_efficiency' => 92.5,
        ];

        $metrics = [
            'total_sleep_duration' => [
                'unit' => 'seconds',
                'title' => 'Total Sleep Duration',
                'type' => 'sleep_metric',
                'category' => 'duration',
            ],
            'deep_sleep_duration' => [
                'unit' => 'seconds',
                'title' => 'Deep Sleep Duration',
                'type' => 'stage_duration',
                'category' => 'stages',
            ],
            'sleep_efficiency' => [
                'unit' => 'percent',
                'title' => 'Sleep Efficiency',
                'type' => 'sleep_metric',
                'category' => 'quality',
            ],
        ];

        $testClass->create_sleep_stage_blocks($this->event, $item, $metrics, $this->plugin);

        $this->event->refresh();
        $blocks = $this->event->blocks;

        $this->assertCount(3, $blocks);

        $totalBlock = $blocks->where('title', 'Total Sleep Duration')->first();
        $this->assertNotNull($totalBlock);
        $this->assertEquals('sleep_stage', $totalBlock->block_type);
        $this->assertEquals(28800, $totalBlock->value);
        $this->assertEquals('seconds', $totalBlock->value_unit);

        $deepBlock = $blocks->where('title', 'Deep Sleep Duration')->first();
        $this->assertNotNull($deepBlock);
        $this->assertEquals(7200, $deepBlock->value);
        $this->assertEquals('stage_duration', $deepBlock->metadata['type']);
        $this->assertEquals('stages', $deepBlock->metadata['category']);
    }

    #[Test]
    public function it_creates_heart_rate_blocks_correctly(): void
    {
        $testClass = new class
        {
            use HasOuraBlocks;

            /**
             * @test
             */
            public function create_heart_rate_blocks($event, $heartRateData, $plugin)
            {
                $this->createHeartRateBlocks($event, $heartRateData, $plugin);
            }
        };

        $heartRateData = [
            'min' => 45,
            'max' => 185,
            'avg' => 78,
            'resting' => 52,
        ];

        $testClass->create_heart_rate_blocks($this->event, $heartRateData, $this->plugin);

        $this->event->refresh();
        $blocks = $this->event->blocks;

        $this->assertCount(4, $blocks);

        $minBlock = $blocks->where('title', 'Minimum Heart Rate')->first();
        $this->assertNotNull($minBlock);
        $this->assertEquals('heart_rate', $minBlock->block_type);
        $this->assertEquals(45, $minBlock->value);
        $this->assertEquals('bpm', $minBlock->value_unit);
        $this->assertEquals('minimum', $minBlock->metadata['type']);

        $maxBlock = $blocks->where('title', 'Maximum Heart Rate')->first();
        $this->assertNotNull($maxBlock);
        $this->assertEquals(185, $maxBlock->value);

        $avgBlock = $blocks->where('title', 'Average Heart Rate')->first();
        $this->assertNotNull($avgBlock);
        $this->assertEquals(78, $avgBlock->value);

        $restingBlock = $blocks->where('title', 'Resting Heart Rate')->first();
        $this->assertNotNull($restingBlock);
        $this->assertEquals(52, $restingBlock->value);
    }

    #[Test]
    public function it_creates_sleep_timing_blocks_correctly(): void
    {
        $testClass = new class
        {
            use HasOuraBlocks;

            /**
             * @test
             */
            public function create_sleep_timing_blocks($event, $item, $fields, $plugin)
            {
                $this->createSleepTimingBlocks($event, $item, $fields, $plugin);
            }
        };

        $item = [
            'bedtime_start' => '2023-10-15T23:30:00Z',
            'bedtime_end' => '2023-10-16T07:15:00Z',
            'wake_up_time' => '2023-10-16T07:15:00Z',
        ];

        $fields = [
            'bedtime_start' => 'Bedtime Start',
            'bedtime_end' => 'Bedtime End',
            'wake_up_time' => 'Wake Up Time',
        ];

        $testClass->create_sleep_timing_blocks($this->event, $item, $fields, $this->plugin);

        $this->event->refresh();
        $blocks = $this->event->blocks;

        $this->assertCount(3, $blocks);

        $bedtimeStartBlock = $blocks->where('title', 'Bedtime Start')->first();
        $this->assertNotNull($bedtimeStartBlock);
        $this->assertEquals('sleep_stage', $bedtimeStartBlock->block_type);
        $this->assertEquals('2023-10-15T23:30:00Z', $bedtimeStartBlock->metadata['value']);
        $this->assertEquals('timing', $bedtimeStartBlock->metadata['type']);
        $this->assertEquals('bedtime_start', $bedtimeStartBlock->metadata['field']);
    }

    #[Test]
    public function it_skips_missing_values_in_block_creation(): void
    {
        $testClass = new class
        {
            use HasOuraBlocks;

            /**
             * @test
             */
            public function create_activity_metric_blocks($event, $item, $metrics, $plugin)
            {
                $this->createActivityMetricBlocks($event, $item, $metrics, $plugin);
            }
        };

        // Item missing some values
        $item = [
            'steps' => 10432,
            // cal_total is missing
            // non_wear_time is null explicitly
            'non_wear_time' => null,
        ];

        $metrics = [
            'steps' => ['unit' => 'count', 'title' => 'Steps', 'type' => 'core_metric'],
            'cal_total' => ['unit' => 'kcal', 'title' => 'Total Calories', 'type' => 'core_metric'],
            'non_wear_time' => ['unit' => 'seconds', 'title' => 'Non-Wear Time', 'type' => 'time_metric'],
        ];

        $testClass->create_activity_metric_blocks($this->event, $item, $metrics, $this->plugin);

        $this->event->refresh();
        $blocks = $this->event->blocks;

        // Only the steps block should be created
        $this->assertCount(1, $blocks);
        $this->assertEquals('Steps', $blocks->first()->title);
    }

    #[Test]
    public function it_provides_correct_standard_metric_configurations(): void
    {
        $testClass = new class
        {
            use HasOuraBlocks;

            public function get_standard_activity_metrics_public()
            {
                return $this->getStandardActivityMetrics();
            }

            public function getMetActivityMetricsPublic()
            {
                return $this->getMetActivityMetrics();
            }

            public function getStandardSleepMetricsPublic()
            {
                return $this->getStandardSleepMetrics();
            }

            public function getStandardSleepTimingFieldsPublic()
            {
                return $this->getStandardSleepTimingFields();
            }
        };

        // Test activity metrics
        $activityMetrics = $testClass->get_standard_activity_metrics_public();
        $this->assertArrayHasKey('steps', $activityMetrics);
        $this->assertEquals('count', $activityMetrics['steps']['unit']);
        $this->assertEquals('Steps', $activityMetrics['steps']['title']);
        $this->assertEquals('core_metric', $activityMetrics['steps']['type']);

        // Test MET metrics
        $metMetrics = $testClass->getMetActivityMetricsPublic();
        $this->assertArrayHasKey('average_met_minutes', $metMetrics);
        $this->assertEquals('met_minutes', $metMetrics['average_met_minutes']['unit']);

        // Test sleep metrics
        $sleepMetrics = $testClass->getStandardSleepMetricsPublic();
        $this->assertArrayHasKey('total_sleep_duration', $sleepMetrics);
        $this->assertEquals('seconds', $sleepMetrics['total_sleep_duration']['unit']);
        $this->assertEquals('duration', $sleepMetrics['total_sleep_duration']['category']);

        // Test sleep timing fields
        $sleepTiming = $testClass->getStandardSleepTimingFieldsPublic();
        $this->assertArrayHasKey('bedtime_start', $sleepTiming);
        $this->assertEquals('Bedtime Start', $sleepTiming['bedtime_start']);
    }
}
