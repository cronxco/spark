<?php

namespace App\Jobs\Data\Immich;

use App\Jobs\Base\BaseProcessingJob;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Person;
use App\Models\Relationship;
use App\Services\ImmichUrlBuilder;
use App\Services\PhotoClusteringService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PhotosData extends BaseProcessingJob
{
    protected ?EventObject $immichUserObject = null;

    /**
     * Get the service name for this job
     */
    protected function getServiceName(): string
    {
        return 'immich';
    }

    /**
     * Get the job type for logging
     */
    protected function getJobType(): string
    {
        return 'photos';
    }

    /**
     * Process the raw photos data and create clustered events
     */
    protected function process(): void
    {
        $photos = $this->rawData['assets'] ?? [];

        if (empty($photos)) {
            Log::info('No photos to process for Immich integration', [
                'integration_id' => $this->integration->id,
            ]);

            return;
        }

        // Get clustering configuration
        $radiusKm = $this->integration->configuration['cluster_radius_km'] ?? 5;
        $windowMinutes = $this->integration->configuration['cluster_window_minutes'] ?? 60;

        // Cluster photos using PhotoClusteringService
        $clusteringService = app(PhotoClusteringService::class);
        $clusters = $clusteringService->clusterPhotos($photos, $radiusKm, $windowMinutes);

        Log::info('Clustered photos for Immich integration', [
            'integration_id' => $this->integration->id,
            'photo_count' => count($photos),
            'cluster_count' => count($clusters),
        ]);

        // Process each cluster
        foreach ($clusters as $clusterData) {
            $this->processCluster($clusterData);
        }
    }

    /**
     * Process a single photo cluster
     */
    protected function processCluster(array $clusterData): void
    {
        // 1. Create/update cluster EventObject
        $cluster = $this->createOrUpdateCluster($clusterData);

        // 2. Create Event for this cluster
        $event = $this->createClusterEvent($cluster, $clusterData);

        if (! $event) {
            // Event already exists (idempotency)
            return;
        }

        // 3. Link to Place if cluster has location
        if ($clusterData['has_location']) {
            $this->linkClusterToPlace($cluster, $event, $clusterData);
        }

        // 4. Collect and link people from all photos in cluster
        $this->linkPeopleToCluster($cluster, $clusterData);

        // 5. Create photo blocks for each individual photo
        $this->createPhotoBlocks($event, $clusterData);

        // 6. Create summary blocks
        $this->createSummaryBlocks($event, $clusterData);
    }

    /**
     * Create or update cluster EventObject
     */
    protected function createOrUpdateCluster(array $clusterData): EventObject
    {
        $locationAddress = null;
        if ($clusterData['has_location']) {
            // Build location address from EXIF or Place
            $locationName = $clusterData['location_name'] ?? null;
            if ($locationName) {
                $locationAddress = $locationName;
            }
        }

        return EventObject::updateOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'photo_cluster',
                'type' => 'immich_cluster',
                'title' => $clusterData['title'],
            ],
            [
                'time' => $clusterData['start_time'],
                'location' => $clusterData['has_location']
                    ? DB::raw("ST_MakePoint({$clusterData['center_lng']}, {$clusterData['center_lat']})")
                    : null,
                'location_address' => $locationAddress,
                'location_source' => $clusterData['has_location'] ? 'immich_exif' : null,
                'metadata' => [
                    'cluster_id' => $clusterData['cluster_id'],
                    'photo_count' => $clusterData['photo_count'],
                    'video_count' => $clusterData['video_count'],
                    'start_time' => $clusterData['start_time']->toIso8601String(),
                    'end_time' => $clusterData['end_time']->toIso8601String(),
                    'has_location' => $clusterData['has_location'],
                    'location_name' => $clusterData['location_name'] ?? null,
                    'time_range_minutes' => $clusterData['start_time']->diffInMinutes($clusterData['end_time']),
                ],
            ]
        );
    }

    /**
     * Create Event for photo cluster
     */
    protected function createClusterEvent(EventObject $cluster, array $clusterData): ?Event
    {
        $sourceId = "immich_cluster_{$clusterData['cluster_id']}";

        // Check if event already exists
        if ($this->eventExists($sourceId)) {
            Log::info('Skipping duplicate cluster event', [
                'integration_id' => $this->integration->id,
                'source_id' => $sourceId,
            ]);

            return null;
        }

        // Get or create Immich user object (actor)
        $actor = $this->getImmichUserObject();

        return Event::create([
            'source_id' => $sourceId,
            'time' => $clusterData['start_time'],
            'integration_id' => $this->integration->id,
            'actor_id' => $actor->id,
            'service' => 'immich',
            'domain' => 'media',
            'action' => 'took_photos',
            'value' => $clusterData['photo_count'],
            'value_unit' => 'photos',
            'target_id' => $cluster->id,
            'event_metadata' => [
                'photo_count' => $clusterData['photo_count'],
                'video_count' => $clusterData['video_count'],
                'time_range_minutes' => $clusterData['start_time']->diffInMinutes($clusterData['end_time']),
                'radius_km' => $clusterData['has_location'] ? $this->calculateClusterRadius($clusterData) : null,
            ],
        ]);
    }

    /**
     * Link cluster to Place if location available
     */
    protected function linkClusterToPlace(EventObject $cluster, Event $event, array $clusterData): void
    {
        try {
            // Use PlaceDetectionService to find/create Place
            $placeService = app(\App\Services\PlaceDetectionService::class);
            $placeService->detectAndLinkPlaceForEvent($event);

            // If EXIF city missing, try to update cluster title with Place name
            if (! $clusterData['location_name']) {
                $place = $event->relationshipsFrom()->where('type', 'occurred_at')->first()?->to;
                if ($place && $place->title) {
                    $cluster->title = "{$place->title} at {$clusterData['hour']}";
                    $cluster->location_address = $place->location_address;
                    $cluster->location_source = 'place_detection';
                    $cluster->save();
                }
            }
        } catch (Exception $e) {
            Log::warning('Failed to link cluster to place', [
                'integration_id' => $this->integration->id,
                'cluster_id' => $clusterData['cluster_id'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Link people to cluster from all photos
     */
    protected function linkPeopleToCluster(EventObject $cluster, array $clusterData): void
    {
        // Extract unique people across all photos
        $peopleMap = []; // Map of person name => photo count

        foreach ($clusterData['photos'] as $photo) {
            $people = $photo['people'] ?? [];
            foreach ($people as $personData) {
                $personName = $personData['name'] ?? 'Unknown Person';

                if (! isset($peopleMap[$personName])) {
                    $peopleMap[$personName] = [
                        'count' => 0,
                        'data' => $personData,
                    ];
                }

                $peopleMap[$personName]['count']++;
            }
        }

        // Create Person objects and relationships
        foreach ($peopleMap as $personName => $info) {
            $personData = $info['data'];

            // Create or update Person
            $person = Person::updateOrCreate(
                [
                    'user_id' => $this->integration->user_id,
                    'concept' => 'person',
                    'type' => 'immich_person',
                    'title' => $personName,
                ],
                [
                    'time' => now(),
                    'metadata' => [
                        'immich_person_id' => $personData['id'] ?? null,
                        'birth_date' => $personData['birthDate'] ?? null,
                        'is_hidden' => $personData['isHidden'] ?? false,
                        'face_count' => ($personData['face_count'] ?? 0) + $info['count'],
                    ],
                ]
            );

            // Create relationship: person tagged_in cluster
            try {
                Relationship::firstOrCreate(
                    [
                        'user_id' => $this->integration->user_id,
                        'from_type' => EventObject::class,
                        'from_id' => $person->id,
                        'to_type' => EventObject::class,
                        'to_id' => $cluster->id,
                        'type' => 'tagged_in',
                    ],
                    [
                        'metadata' => [
                            'photo_count' => $info['count'],
                        ],
                    ]
                );
            } catch (Exception $e) {
                Log::warning('Failed to create person relationship', [
                    'integration_id' => $this->integration->id,
                    'person_title' => $personName,
                    'cluster_id' => $clusterData['cluster_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Create photo blocks for each individual photo
     */
    protected function createPhotoBlocks(Event $event, array $clusterData): void
    {
        $urlBuilder = app(ImmichUrlBuilder::class);
        $serverUrl = $this->integration->group->auth_metadata['server_url'] ?? null;

        if (! $serverUrl) {
            Log::warning('No server URL configured, skipping photo block URLs', [
                'integration_id' => $this->integration->id,
            ]);
        }

        foreach ($clusterData['photos'] as $photo) {
            $exif = $photo['exifInfo'] ?? [];
            $peopleNames = array_map(fn ($p) => $p['name'] ?? 'Unknown', $photo['people'] ?? []);

            $event->createBlock([
                'block_type' => 'immich_photo',
                'title' => $photo['originalFileName'] ?? 'Photo',
                'time' => Carbon::parse($photo['fileCreatedAt'] ?? $photo['createdAt']),
                'metadata' => [
                    'immich_asset_id' => $photo['id'],
                    'type' => $photo['type'] ?? 'IMAGE',
                    'timestamp' => $photo['fileCreatedAt'] ?? $photo['createdAt'],
                    'camera_make' => $exif['make'] ?? null,
                    'camera_model' => $exif['model'] ?? null,
                    'lens_model' => $exif['lensModel'] ?? null,
                    'f_number' => $exif['fNumber'] ?? null,
                    'focal_length' => $exif['focalLength'] ?? null,
                    'iso' => $exif['iso'] ?? null,
                    'exposure_time' => $exif['exposureTime'] ?? null,
                    'latitude' => $exif['latitude'] ?? null,
                    'longitude' => $exif['longitude'] ?? null,
                    'people' => $peopleNames,
                    'thumbnail_url' => $serverUrl ? $urlBuilder->getThumbnailUrl($serverUrl, $photo['id'], 'preview') : null,
                    'view_url' => $serverUrl ? $urlBuilder->getAssetUrl($serverUrl, $photo['id']) : null,
                    'is_favorite' => $photo['isFavorite'] ?? false,
                    'is_archived' => $photo['isArchived'] ?? false,
                    'duration' => $photo['duration'] ?? null,
                ],
            ]);
        }
    }

    /**
     * Create summary blocks for cluster
     */
    protected function createSummaryBlocks(Event $event, array $clusterData): void
    {
        $urlBuilder = app(ImmichUrlBuilder::class);
        $serverUrl = $this->integration->group->auth_metadata['server_url'] ?? null;

        // Get thumbnail URLs for first 6 photos
        $thumbnailUrls = [];
        if ($serverUrl) {
            $photoSample = array_slice($clusterData['photos'], 0, 6);
            foreach ($photoSample as $photo) {
                $thumbnailUrls[] = $urlBuilder->getThumbnailUrl($serverUrl, $photo['id'], 'thumbnail');
            }
        }

        // Create cluster summary block
        $event->createBlock([
            'block_type' => 'cluster_summary',
            'title' => 'Cluster Summary',
            'value' => $clusterData['photo_count'],
            'value_unit' => 'photos',
            'time' => $clusterData['start_time'],
            'metadata' => [
                'photo_count' => $clusterData['photo_count'],
                'video_count' => $clusterData['video_count'],
                'time_range' => $clusterData['start_time']->format('g:ia') . ' - ' . $clusterData['end_time']->format('g:ia'),
                'location_name' => $clusterData['location_name'] ?? null,
                'thumbnail_urls' => $thumbnailUrls,
            ],
        ]);

        // Create cluster people block if people present
        $peopleData = $this->extractPeopleFromCluster($clusterData);
        if (! empty($peopleData)) {
            $event->createBlock([
                'block_type' => 'cluster_people',
                'title' => 'People in Cluster',
                'value' => count($peopleData),
                'value_unit' => 'people',
                'time' => $clusterData['start_time'],
                'metadata' => [
                    'people' => $peopleData,
                ],
            ]);
        }
    }

    /**
     * Extract people data from cluster for display
     */
    protected function extractPeopleFromCluster(array $clusterData): array
    {
        $peopleMap = [];

        foreach ($clusterData['photos'] as $photo) {
            $people = $photo['people'] ?? [];
            foreach ($people as $personData) {
                $personName = $personData['name'] ?? 'Unknown Person';

                if (! isset($peopleMap[$personName])) {
                    $peopleMap[$personName] = [
                        'name' => $personName,
                        'photo_count' => 0,
                        'person_id' => null,
                    ];
                }

                $peopleMap[$personName]['photo_count']++;
            }
        }

        // Get Person IDs for linking
        foreach ($peopleMap as $name => &$data) {
            $person = Person::where('user_id', $this->integration->user_id)
                ->where('title', $name)
                ->first();

            if ($person) {
                $data['person_id'] = $person->id;
            }
        }

        return array_values($peopleMap);
    }

    /**
     * Calculate cluster radius (maximum distance from center)
     */
    protected function calculateClusterRadius(array $clusterData): float
    {
        if (! $clusterData['has_location'] || empty($clusterData['photos'])) {
            return 0.0;
        }

        $maxDistance = 0.0;
        $centerLat = $clusterData['center_lat'];
        $centerLng = $clusterData['center_lng'];

        foreach ($clusterData['photos'] as $photo) {
            $lat = $photo['exifInfo']['latitude'] ?? null;
            $lng = $photo['exifInfo']['longitude'] ?? null;

            if ($lat && $lng) {
                $distance = $this->calculateDistance($centerLat, $centerLng, $lat, $lng);
                $maxDistance = max($maxDistance, $distance);
            }
        }

        return round($maxDistance, 2);
    }

    /**
     * Calculate distance between two points (Haversine formula)
     */
    protected function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
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
     * Get or create Immich user object (actor)
     */
    protected function getImmichUserObject(): EventObject
    {
        if ($this->immichUserObject) {
            return $this->immichUserObject;
        }

        $serverUrl = $this->integration->group->auth_metadata['server_url'] ?? 'Immich';

        $this->immichUserObject = EventObject::updateOrCreate(
            [
                'user_id' => $this->integration->user_id,
                'concept' => 'user',
                'type' => 'immich_user',
                'title' => 'Immich User',
            ],
            [
                'time' => now(),
                'metadata' => [
                    'server_url' => $serverUrl,
                ],
            ]
        );

        return $this->immichUserObject;
    }
}
