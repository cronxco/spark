<?php

namespace Tests\Unit\Services;

use App\Services\InsightDeduplicationService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class InsightDeduplicationServiceTest extends TestCase
{
    protected InsightDeduplicationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new InsightDeduplicationService;
    }

    protected function tearDown(): void
    {
        // Clear all seen insights after each test
        $this->service->clearSeenInsights('test-user-id');

        parent::tearDown();
    }

    /** @test */
    public function detects_exact_duplicate_insights()
    {
        $insight = [
            'title' => 'Sleep quality declining',
            'description' => 'Your HRV has dropped 15% this week',
            'confidence' => 0.9,
        ];

        // Mark as seen
        $this->service->markAsSeen($insight, 'health', 'test-user-id');

        // Check if duplicate
        $result = $this->service->isDuplicate($insight, 'health', 'test-user-id');

        $this->assertTrue($result['is_duplicate']);
        $this->assertEquals(1.0, $result['similarity_score']);
    }

    /** @test */
    public function does_not_detect_different_insights_as_duplicates()
    {
        $insight1 = [
            'title' => 'Sleep quality declining',
            'description' => 'Your HRV has dropped 15% this week',
            'confidence' => 0.9,
        ];

        $insight2 = [
            'title' => 'Spending increased significantly',
            'description' => 'You spent 40% more this month',
            'confidence' => 0.85,
        ];

        $this->service->markAsSeen($insight1, 'health', 'test-user-id');

        $result = $this->service->isDuplicate($insight2, 'health', 'test-user-id');

        $this->assertFalse($result['is_duplicate']);
    }

    /** @test */
    public function detects_similar_insights_with_minor_rewording()
    {
        $insight1 = [
            'title' => 'Sleep quality declining',
            'description' => 'Your HRV has dropped 15% this week',
            'confidence' => 0.9,
        ];

        $insight2 = [
            'title' => 'Sleep quality declining',
            'description' => 'Your HRV dropped 15% this week',
            'confidence' => 0.88,
        ];

        $this->service->markAsSeen($insight1, 'health', 'test-user-id');

        $result = $this->service->isDuplicate($insight2, 'health', 'test-user-id');

        $this->assertTrue($result['is_duplicate']);
        $this->assertGreaterThan(0.85, $result['similarity_score']);
    }

    /** @test */
    public function normalizes_text_for_comparison()
    {
        $insight1 = [
            'title' => 'Sleep Quality Declining!!!',
            'description' => 'Your HRV has dropped 15% this week.',
            'confidence' => 0.9,
        ];

        $insight2 = [
            'title' => 'sleep quality declining',
            'description' => 'your hrv has dropped 15% this week',
            'confidence' => 0.9,
        ];

        $this->service->markAsSeen($insight1, 'health', 'test-user-id');

        $result = $this->service->isDuplicate($insight2, 'health', 'test-user-id');

        $this->assertTrue($result['is_duplicate']);
        $this->assertEquals(1.0, $result['similarity_score']);
    }

    /** @test */
    public function scopes_duplicates_by_domain()
    {
        $insight = [
            'title' => 'Important insight',
            'description' => 'This is critical information',
            'confidence' => 0.9,
        ];

        // Mark as seen in health domain
        $this->service->markAsSeen($insight, 'health', 'test-user-id');

        // Check in money domain (should not be duplicate)
        $result = $this->service->isDuplicate($insight, 'money', 'test-user-id');

        $this->assertFalse($result['is_duplicate']);
    }

    /** @test */
    public function scopes_duplicates_by_user()
    {
        $insight = [
            'title' => 'Important insight',
            'description' => 'This is critical information',
            'confidence' => 0.9,
        ];

        // Mark as seen for user 1
        $this->service->markAsSeen($insight, 'health', 'test-user-id');

        // Check for user 2 (should not be duplicate)
        $result = $this->service->isDuplicate($insight, 'health', 2);

        $this->assertFalse($result['is_duplicate']);

        // Clean up user 2
        $this->service->clearSeenInsights(2);
    }

    /** @test */
    public function stores_multiple_insights()
    {
        $insight1 = [
            'title' => 'First insight',
            'description' => 'First description',
            'confidence' => 0.9,
        ];

        $insight2 = [
            'title' => 'Second insight',
            'description' => 'Second description',
            'confidence' => 0.85,
        ];

        $this->service->markAsSeen($insight1, 'health', 'test-user-id');
        $this->service->markAsSeen($insight2, 'health', 'test-user-id');

        $stats = $this->service->getStatistics('test-user-id');

        $this->assertEquals(2, $stats['health']);
        $this->assertEquals(2, $stats['total']);
    }

    /** @test */
    public function clears_seen_insights_for_domain()
    {
        $insight = [
            'title' => 'Test insight',
            'description' => 'Test description',
            'confidence' => 0.9,
        ];

        $this->service->markAsSeen($insight, 'health', 'test-user-id');

        $stats = $this->service->getStatistics('test-user-id');
        $this->assertEquals(1, $stats['health']);

        $this->service->clearSeenInsights('test-user-id', 'health');

        $stats = $this->service->getStatistics('test-user-id');
        $this->assertEquals(0, $stats['health']);
    }

    /** @test */
    public function clears_all_domains_when_no_domain_specified()
    {
        $insight = [
            'title' => 'Test insight',
            'description' => 'Test description',
            'confidence' => 0.9,
        ];

        $this->service->markAsSeen($insight, 'health', 'test-user-id');
        $this->service->markAsSeen($insight, 'money', 'test-user-id');

        $stats = $this->service->getStatistics('test-user-id');
        $this->assertEquals(2, $stats['total']);

        $this->service->clearSeenInsights('test-user-id');

        $stats = $this->service->getStatistics('test-user-id');
        $this->assertEquals(0, $stats['total']);
    }

    /** @test */
    public function handles_long_text_similarity()
    {
        $longDescription = str_repeat('This is a very long description with many words. ', 50);

        $insight1 = [
            'title' => 'Long insight',
            'description' => $longDescription,
            'confidence' => 0.9,
        ];

        $insight2 = [
            'title' => 'Long insight',
            'description' => $longDescription . ' Extra text.',
            'confidence' => 0.9,
        ];

        $this->service->markAsSeen($insight1, 'health', 'test-user-id');

        $result = $this->service->isDuplicate($insight2, 'health', 'test-user-id');

        $this->assertTrue($result['is_duplicate']);
        $this->assertGreaterThan(0.85, $result['similarity_score']);
    }

    /** @test */
    public function allows_custom_similarity_threshold()
    {
        // Set very high threshold (require almost identical text)
        $this->service->setSimilarityThreshold(0.95);

        $insight1 = [
            'title' => 'Sleep declining',
            'description' => 'HRV dropped 15%',
            'confidence' => 0.9,
        ];

        $insight2 = [
            'title' => 'Sleep quality declining',
            'description' => 'Heart rate variability dropped 15% this week',
            'confidence' => 0.9,
        ];

        $this->service->markAsSeen($insight1, 'health', 'test-user-id');

        $result = $this->service->isDuplicate($insight2, 'health', 'test-user-id');

        // Should NOT be duplicate with high threshold
        $this->assertFalse($result['is_duplicate']);
    }

    /** @test */
    public function returns_similarity_score_even_when_not_duplicate()
    {
        $insight1 = [
            'title' => 'Sleep quality declining',
            'description' => 'Your HRV dropped 15%',
            'confidence' => 0.9,
        ];

        $insight2 = [
            'title' => 'Spending increased',
            'description' => 'You spent more money',
            'confidence' => 0.85,
        ];

        $this->service->markAsSeen($insight1, 'health', 'test-user-id');

        $result = $this->service->isDuplicate($insight2, 'health', 'test-user-id');

        $this->assertFalse($result['is_duplicate']);
        $this->assertNull($result['similarity_score']); // No similar match found
    }

    /** @test */
    public function caches_insights_for_24_hours()
    {
        $insight = [
            'title' => 'Test insight',
            'description' => 'Test description',
            'confidence' => 0.9,
        ];

        $cacheKey = 'insight_dedup:test-user-id:health';

        // Mark as seen
        $this->service->markAsSeen($insight, 'health', 'test-user-id');

        // Verify it's in cache
        $this->assertTrue(Cache::has($cacheKey));

        $cached = Cache::get($cacheKey);
        $this->assertIsArray($cached);
        $this->assertNotEmpty($cached);
    }

    /** @test */
    public function handles_insights_without_title_or_description()
    {
        $invalidInsight = [
            'confidence' => 0.9,
        ];

        $result = $this->service->isDuplicate($invalidInsight, 'health', 'test-user-id');

        $this->assertFalse($result['is_duplicate']);
        $this->assertNull($result['similarity_score']);
    }
}
