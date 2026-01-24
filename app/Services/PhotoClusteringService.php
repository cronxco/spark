<?php

namespace App\Services;

use Carbon\Carbon;

class PhotoClusteringService
{
    /**
     * Cluster photos by temporal-spatial proximity
     *
     * @param  array  $photos  Array of photo assets from Immich API
     * @param  int  $radiusKm  Maximum distance in km for clustering
     * @param  int  $windowMinutes  Maximum time window in minutes
     * @return array Array of clusters with photos grouped
     */
    public function clusterPhotos(array $photos, int $radiusKm = 5, int $windowMinutes = 60): array
    {
        if (empty($photos)) {
            return [];
        }

        // Sort photos by timestamp (ascending)
        usort($photos, function ($a, $b) {
            $timeA = $this->extractTimestamp($a);
            $timeB = $this->extractTimestamp($b);

            return $timeA <=> $timeB;
        });

        $clusters = [];

        foreach ($photos as $photo) {
            $timestamp = $this->extractTimestamp($photo);
            $latitude = $photo['exifInfo']['latitude'] ?? null;
            $longitude = $photo['exifInfo']['longitude'] ?? null;

            // Find candidate clusters that this photo could belong to
            $candidateClusters = $this->findCandidateClusters(
                $clusters,
                $timestamp,
                $latitude,
                $longitude,
                $radiusKm,
                $windowMinutes
            );

            if (empty($candidateClusters)) {
                // Create new cluster with this photo
                $clusters[] = $this->createNewCluster($photo, $timestamp, $latitude, $longitude);
            } else {
                // Assign to nearest cluster
                $nearestCluster = $this->findNearestCluster(
                    $clusters,
                    $candidateClusters,
                    $timestamp,
                    $latitude,
                    $longitude,
                    $radiusKm,
                    $windowMinutes
                );

                // Add photo to cluster and update bounds
                $this->addPhotoToCluster($clusters[$nearestCluster], $photo, $timestamp, $latitude, $longitude);
            }
        }

        // Finalize clusters (calculate center points, location names, titles)
        return array_map(function ($cluster) {
            return $this->finalizeCluster($cluster);
        }, $clusters);
    }

    /**
     * Extract timestamp from photo asset
     */
    protected function extractTimestamp(array $photo): Carbon
    {
        // Prefer fileCreatedAt, fall back to localDateTime or createdAt
        $timestamp = $photo['fileCreatedAt']
            ?? $photo['localDateTime']
            ?? $photo['createdAt'];

        return Carbon::parse($timestamp);
    }

    /**
     * Find clusters that this photo could belong to
     */
    protected function findCandidateClusters(
        array $clusters,
        Carbon $timestamp,
        ?float $latitude,
        ?float $longitude,
        int $radiusKm,
        int $windowMinutes
    ): array {
        $candidates = [];

        foreach ($clusters as $index => $cluster) {
            if ($this->isWithinCluster($cluster, $timestamp, $latitude, $longitude, $radiusKm, $windowMinutes)) {
                $candidates[] = $index;
            }
        }

        return $candidates;
    }

    /**
     * Check if photo fits within cluster bounds
     */
    protected function isWithinCluster(
        array $cluster,
        Carbon $timestamp,
        ?float $latitude,
        ?float $longitude,
        int $radiusKm,
        int $windowMinutes
    ): bool {
        // Check time window
        $timeDeltaMinutes = abs($timestamp->diffInMinutes($cluster['start_time']));
        if ($timeDeltaMinutes > $windowMinutes) {
            return false;
        }

        // Check distance (if both photo and cluster have GPS)
        if ($latitude !== null && $longitude !== null && $cluster['has_location']) {
            $distance = $this->calculateDistance(
                $latitude,
                $longitude,
                $cluster['center_lat'],
                $cluster['center_lng']
            );

            if ($distance > $radiusKm) {
                return false;
            }
        }

        return true;
    }

    /**
     * Find nearest cluster from candidates using distance+time score
     */
    protected function findNearestCluster(
        array &$clusters,
        array $candidateIndices,
        Carbon $timestamp,
        ?float $latitude,
        ?float $longitude,
        int $radiusKm,
        int $windowMinutes
    ): int {
        $bestScore = PHP_FLOAT_MAX;
        $bestIndex = $candidateIndices[0];

        foreach ($candidateIndices as $index) {
            $cluster = $clusters[$index];
            $score = $this->calculateScore(
                $timestamp,
                $latitude,
                $longitude,
                $cluster,
                $radiusKm,
                $windowMinutes
            );

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }

    /**
     * Calculate proximity score (distance + time), lower is better
     */
    protected function calculateScore(
        Carbon $timestamp,
        ?float $latitude,
        ?float $longitude,
        array $cluster,
        int $radiusKm,
        int $windowMinutes
    ): float {
        $distanceScore = 0;
        $timeScore = 0;

        // Calculate distance score (if GPS available)
        if ($latitude !== null && $longitude !== null && $cluster['has_location']) {
            $distance = $this->calculateDistance(
                $latitude,
                $longitude,
                $cluster['center_lat'],
                $cluster['center_lng']
            );
            $distanceScore = $distance / $radiusKm; // Normalize to 0-1+
        }

        // Calculate time score
        $timeDeltaMinutes = abs($timestamp->diffInMinutes($cluster['start_time']));
        $timeScore = $timeDeltaMinutes / $windowMinutes; // Normalize to 0-1+

        return $distanceScore + $timeScore;
    }

    /**
     * Calculate distance between two GPS coordinates using Haversine formula
     * Returns distance in kilometers
     */
    protected function calculateDistance(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2
    ): float {
        $earthRadiusKm = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }

    /**
     * Create new cluster with first photo
     */
    protected function createNewCluster(
        array $photo,
        Carbon $timestamp,
        ?float $latitude,
        ?float $longitude
    ): array {
        return [
            'photos' => [$photo],
            'start_time' => $timestamp,
            'end_time' => $timestamp,
            'has_location' => $latitude !== null && $longitude !== null,
            'center_lat' => $latitude,
            'center_lng' => $longitude,
            'location_names' => $this->extractLocationName($photo) ? [$this->extractLocationName($photo)] : [],
        ];
    }

    /**
     * Add photo to existing cluster and update bounds
     */
    protected function addPhotoToCluster(
        array &$cluster,
        array $photo,
        Carbon $timestamp,
        ?float $latitude,
        ?float $longitude
    ): void {
        $cluster['photos'][] = $photo;

        // Update time range
        if ($timestamp->isBefore($cluster['start_time'])) {
            $cluster['start_time'] = $timestamp;
        }
        if ($timestamp->isAfter($cluster['end_time'])) {
            $cluster['end_time'] = $timestamp;
        }

        // Update location data
        if ($latitude !== null && $longitude !== null) {
            if (! $cluster['has_location']) {
                // First photo with GPS in cluster
                $cluster['has_location'] = true;
                $cluster['center_lat'] = $latitude;
                $cluster['center_lng'] = $longitude;
            } else {
                // Recalculate center as average
                $totalLat = $cluster['center_lat'] * (count($cluster['photos']) - 1) + $latitude;
                $totalLng = $cluster['center_lng'] * (count($cluster['photos']) - 1) + $longitude;
                $cluster['center_lat'] = $totalLat / count($cluster['photos']);
                $cluster['center_lng'] = $totalLng / count($cluster['photos']);
            }

            // Collect location name
            $locationName = $this->extractLocationName($photo);
            if ($locationName && ! in_array($locationName, $cluster['location_names'])) {
                $cluster['location_names'][] = $locationName;
            }
        }
    }

    /**
     * Finalize cluster data (calculate final values, generate title)
     */
    protected function finalizeCluster(array $cluster): array
    {
        // Determine location name (most common from EXIF)
        $locationName = null;
        if (! empty($cluster['location_names'])) {
            // Use most common location name
            $counts = array_count_values($cluster['location_names']);
            arsort($counts);
            $locationName = array_key_first($counts);
        }

        // Format hour
        $hour = $this->formatHour($cluster['start_time']);

        // Generate title
        $title = $this->generateClusterTitle($locationName, $hour);

        // Generate stable cluster ID
        $clusterId = $this->generateClusterId($cluster, $locationName, $hour);

        return [
            'photos' => $cluster['photos'],
            'start_time' => $cluster['start_time'],
            'end_time' => $cluster['end_time'],
            'center_lat' => $cluster['center_lat'],
            'center_lng' => $cluster['center_lng'],
            'has_location' => $cluster['has_location'],
            'location_name' => $locationName,
            'hour' => $hour,
            'title' => $title,
            'cluster_id' => $clusterId,
            'photo_count' => count($cluster['photos']),
            'video_count' => count(array_filter($cluster['photos'], fn ($p) => ($p['type'] ?? 'IMAGE') === 'VIDEO')),
        ];
    }

    /**
     * Extract location name from photo EXIF
     */
    protected function extractLocationName(array $photo): ?string
    {
        return $photo['exifInfo']['city'] ?? null;
    }

    /**
     * Format hour for display (e.g., "10am", "2pm", "11pm")
     */
    protected function formatHour(Carbon $timestamp): string
    {
        return $timestamp->format('ga'); // e.g., "10am", "2pm"
    }

    /**
     * Generate cluster title
     */
    protected function generateClusterTitle(?string $locationName, string $hour): string
    {
        if ($locationName) {
            return "{$locationName} at {$hour}";
        }

        return "Photos at {$hour}";
    }

    /**
     * Generate stable cluster ID for idempotency
     * Hash based on location (if available) and rounded timestamp
     */
    protected function generateClusterId(array $cluster, ?string $locationName, string $hour): string
    {
        $components = [
            $cluster['start_time']->format('Y-m-d H'), // Hour precision
            $locationName ?? 'no-location',
            $cluster['has_location'] ? round($cluster['center_lat'], 2).'-'.round($cluster['center_lng'], 2) : 'no-gps',
        ];

        return md5(implode('|', $components));
    }
}
