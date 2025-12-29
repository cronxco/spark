<?php

namespace Tests\Unit\Services;

use App\Services\PhotoClusteringService;
use Tests\TestCase;

class PhotoClusteringServiceTest extends TestCase
{
    protected PhotoClusteringService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PhotoClusteringService;
    }

    /** @test */
    public function it_clusters_photos_by_time_and_location()
    {
        $photos = [
            // Cluster 1: Turku at 10am
            $this->createPhoto('2024-01-15 10:15:00', 60.4514902, 22.2669201, 'Turku'),
            $this->createPhoto('2024-01-15 10:30:00', 60.4520000, 22.2675000, 'Turku'),
            $this->createPhoto('2024-01-15 10:45:00', 60.4518000, 22.2670000, 'Turku'),

            // Cluster 2: Helsinki at 3pm (different location, different time)
            $this->createPhoto('2024-01-15 15:00:00', 60.1699, 24.9384, 'Helsinki'),
            $this->createPhoto('2024-01-15 15:20:00', 60.1705, 24.9390, 'Helsinki'),
        ];

        $clusters = $this->service->clusterPhotos($photos, 5, 60);

        $this->assertCount(2, $clusters);
        $this->assertCount(3, $clusters[0]['photos']);
        $this->assertCount(2, $clusters[1]['photos']);
    }

    /** @test */
    public function it_creates_separate_clusters_for_photos_beyond_distance_threshold()
    {
        $photos = [
            // Turku
            $this->createPhoto('2024-01-15 10:00:00', 60.4514902, 22.2669201, 'Turku'),
            // London (too far, even though same time)
            $this->createPhoto('2024-01-15 10:10:00', 51.5074, -0.1278, 'London'),
        ];

        $clusters = $this->service->clusterPhotos($photos, 5, 60);

        // Should be 2 clusters due to distance
        $this->assertCount(2, $clusters);
    }

    /** @test */
    public function it_creates_separate_clusters_for_photos_beyond_time_threshold()
    {
        $photos = [
            // 10am
            $this->createPhoto('2024-01-15 10:00:00', 60.4514902, 22.2669201, 'Turku'),
            // 12pm (2 hours later, beyond 1 hour window)
            $this->createPhoto('2024-01-15 12:00:00', 60.4520000, 22.2675000, 'Turku'),
        ];

        $clusters = $this->service->clusterPhotos($photos, 5, 60);

        // Should be 2 clusters due to time
        $this->assertCount(2, $clusters);
    }

    /** @test */
    public function it_handles_time_only_clustering_for_photos_without_gps()
    {
        $photos = [
            // No GPS data, but within 1 hour
            $this->createPhoto('2024-01-15 10:00:00', null, null, null),
            $this->createPhoto('2024-01-15 10:30:00', null, null, null),
            $this->createPhoto('2024-01-15 10:45:00', null, null, null),
        ];

        $clusters = $this->service->clusterPhotos($photos, 5, 60);

        $this->assertCount(1, $clusters);
        $this->assertCount(3, $clusters[0]['photos']);
        $this->assertFalse($clusters[0]['has_location']);
    }

    /** @test */
    public function it_generates_titles_with_location_names()
    {
        $photos = [
            $this->createPhoto('2024-01-15 10:15:00', 60.4514902, 22.2669201, 'Turku'),
        ];

        $clusters = $this->service->clusterPhotos($photos, 5, 60);

        $this->assertStringContainsString('Turku', $clusters[0]['title']);
        $this->assertStringContainsString('10am', $clusters[0]['title']);
    }

    /** @test */
    public function it_generates_generic_titles_without_location()
    {
        $photos = [
            $this->createPhoto('2024-01-15 14:15:00', null, null, null),
        ];

        $clusters = $this->service->clusterPhotos($photos, 5, 60);

        $this->assertEquals('Photos at 2pm', $clusters[0]['title']);
    }

    /** @test */
    public function it_assigns_photos_to_nearest_cluster()
    {
        $photos = [
            // Cluster center
            $this->createPhoto('2024-01-15 10:00:00', 60.4514902, 22.2669201, 'Turku'),
            // Slightly closer photo added later
            $this->createPhoto('2024-01-15 10:30:00', 60.4516000, 22.2670000, 'Turku'),
        ];

        $clusters = $this->service->clusterPhotos($photos, 5, 60);

        $this->assertCount(1, $clusters);
        $this->assertCount(2, $clusters[0]['photos']);
    }

    /** @test */
    public function it_handles_mixed_gps_and_no_gps_photos()
    {
        $photos = [
            // With GPS
            $this->createPhoto('2024-01-15 10:00:00', 60.4514902, 22.2669201, 'Turku'),
            // Without GPS, but same time window
            $this->createPhoto('2024-01-15 10:15:00', null, null, null),
        ];

        $clusters = $this->service->clusterPhotos($photos, 5, 60);

        // Should create 1 cluster since time-only photos can join location clusters
        $this->assertCount(1, $clusters);
        $this->assertCount(2, $clusters[0]['photos']);
    }

    /** @test */
    public function it_generates_stable_cluster_ids()
    {
        $photos = [
            $this->createPhoto('2024-01-15 10:15:00', 60.4514902, 22.2669201, 'Turku'),
        ];

        $clusters1 = $this->service->clusterPhotos($photos, 5, 60);
        $clusters2 = $this->service->clusterPhotos($photos, 5, 60);

        // Same photos should generate same cluster ID (idempotency)
        $this->assertEquals($clusters1[0]['cluster_id'], $clusters2[0]['cluster_id']);
    }

    /** @test */
    public function it_counts_photos_and_videos_separately()
    {
        $photos = [
            $this->createPhoto('2024-01-15 10:00:00', 60.4514902, 22.2669201, 'Turku', 'IMAGE'),
            $this->createPhoto('2024-01-15 10:15:00', 60.4520000, 22.2675000, 'Turku', 'VIDEO'),
            $this->createPhoto('2024-01-15 10:30:00', 60.4518000, 22.2670000, 'Turku', 'IMAGE'),
        ];

        $clusters = $this->service->clusterPhotos($photos, 5, 60);

        $this->assertEquals(3, $clusters[0]['photo_count']);
        $this->assertEquals(1, $clusters[0]['video_count']);
    }

    /** @test */
    public function it_handles_empty_photo_array()
    {
        $clusters = $this->service->clusterPhotos([], 5, 60);

        $this->assertEmpty($clusters);
    }

    /** @test */
    public function it_handles_single_photo()
    {
        $photos = [
            $this->createPhoto('2024-01-15 10:00:00', 60.4514902, 22.2669201, 'Turku'),
        ];

        $clusters = $this->service->clusterPhotos($photos, 5, 60);

        $this->assertCount(1, $clusters);
        $this->assertCount(1, $clusters[0]['photos']);
    }

    /**
     * Helper to create a photo asset matching Immich API format
     */
    protected function createPhoto(
        string $timestamp,
        ?float $latitude,
        ?float $longitude,
        ?string $city,
        string $type = 'IMAGE'
    ): array {
        return [
            'id' => 'photo-' . md5($timestamp . $latitude . $longitude),
            'type' => $type,
            'fileCreatedAt' => $timestamp,
            'createdAt' => $timestamp,
            'localDateTime' => $timestamp,
            'exifInfo' => array_filter([
                'latitude' => $latitude,
                'longitude' => $longitude,
                'city' => $city,
            ], fn ($v) => $v !== null),
        ];
    }
}
