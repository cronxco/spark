<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Place;
use App\Models\Relationship;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class PlaceDetectionService
{
    public function __construct(
        protected GeocodingService $geocodingService
    ) {}

    /**
     * Detect existing place or create new one from coordinates
     */
    public function detectOrCreatePlace(
        float $latitude,
        float $longitude,
        ?string $address,
        User $user,
        int $searchRadiusMeters = 50
    ): Place {
        // 1. Check if place already exists within radius
        $existing = $this->findNearbyPlace($latitude, $longitude, $user, $searchRadiusMeters);

        if ($existing) {
            $existing->recordVisit();
            Log::info('Matched existing place', [
                'place_id' => $existing->id,
                'title' => $existing->title,
                'visit_count' => $existing->visit_count,
            ]);

            return $existing;
        }

        // 2. Reverse geocode if no address provided
        if (! $address) {
            $geocodeResult = $this->geocodingService->reverseGeocode($latitude, $longitude);
            $address = $geocodeResult['formatted_address'] ?? null;
        }

        // 3. Generate place title from address
        $title = $this->generatePlaceTitle($address, $latitude, $longitude);

        // 4. Guess category from address
        $category = $this->guessCategory($address);

        // 5. Create new place
        $place = Place::create([
            'user_id' => $user->id,
            'concept' => 'place',
            'type' => 'discovered_place',
            'title' => $title,
            'time' => now(),
            'location_address' => $address,
            'metadata' => [
                'visit_count' => 1,
                'first_visit_at' => now()->toIso8601String(),
                'last_visit_at' => now()->toIso8601String(),
                'category' => $category,
                'detection_radius_meters' => $searchRadiusMeters,
                'is_favorite' => false,
            ],
        ]);

        $place->setLocation($latitude, $longitude, $address, 'place_detection');

        Log::info('Created new place', [
            'place_id' => $place->id,
            'title' => $place->title,
            'category' => $category,
        ]);

        return $place;
    }

    /**
     * Link an event to a place via relationship
     */
    public function linkEventToPlace(Event $event, Place $place): Relationship
    {
        // Events don't have user_id directly - get it from integration
        $event->loadMissing('integration');

        return Relationship::createRelationship([
            'user_id' => $event->integration->user_id,
            'from_type' => Event::class,
            'from_id' => $event->id,
            'to_type' => EventObject::class, // Place extends EventObject, use EventObject::class for consistency
            'to_id' => $place->id,
            'type' => 'occurred_at',
        ]);
    }

    /**
     * Detect place for an event and link them
     */
    public function detectAndLinkPlaceForEvent(Event $event, int $searchRadiusMeters = 50): ?Place
    {
        if (! $event->hasLocation()) {
            return null;
        }

        // Load integration to get user_id (events don't have user_id directly)
        $event->loadMissing('integration');
        $user = $event->integration?->user;

        if (! $user) {
            Log::warning('Cannot detect place for event - user not found', [
                'event_id' => $event->id,
                'integration_id' => $event->integration_id,
            ]);

            return null;
        }

        // Ensure latitude and longitude are not null
        if ($event->latitude === null || $event->longitude === null) {
            Log::warning('Cannot detect place for event - missing coordinates', [
                'event_id' => $event->id,
                'has_location' => $event->hasLocation(),
            ]);

            return null;
        }

        $place = $this->detectOrCreatePlace(
            $event->latitude,
            $event->longitude,
            $event->location_address,
            $user,
            $searchRadiusMeters
        );

        $this->linkEventToPlace($event, $place);

        return $place;
    }

    /**
     * Find existing place within radius of coordinates
     */
    public function findNearbyPlace(
        float $latitude,
        float $longitude,
        User $user,
        int $radiusMeters = 50
    ): ?Place {
        return Place::query()
            ->where('user_id', $user->id)
            ->withinRadius($latitude, $longitude, $radiusMeters)
            ->orderByRaw(
                'ST_Distance(location::geography, ST_MakePoint(?, ?)::geography)',
                [$longitude, $latitude]
            )
            ->first();
    }

    /**
     * Suggest if place should be marked as "Home" based on visit patterns
     */
    public function suggestHomePlace(User $user): ?Place
    {
        // Find most visited place during typical home hours (evening/night/morning)
        return Place::query()
            ->where('user_id', $user->id)
            ->whereNull('metadata->category') // Not already categorized
            ->orderByVisitCount('desc')
            ->first();
    }

    /**
     * Suggest if place should be marked as "Work" based on visit patterns
     */
    public function suggestWorkPlace(User $user): ?Place
    {
        // Find place visited regularly during work hours (9-5 weekdays)
        // This would require analyzing event timestamps - simplified for now
        return Place::query()
            ->where('user_id', $user->id)
            ->whereNull('metadata->category')
            ->orderByVisitCount('desc')
            ->skip(1) // Skip most visited (likely home)
            ->first();
    }

    /**
     * Merge two places into one (when duplicates are detected)
     */
    public function mergePlaces(Place $keepPlace, Place $removePlace): Place
    {
        // Move all relationships from removePlace to keepPlace
        Relationship::where('to_type', EventObject::class)
            ->where('to_id', $removePlace->id)
            ->update(['to_id' => $keepPlace->id]);

        Relationship::where('from_type', EventObject::class)
            ->where('from_id', $removePlace->id)
            ->update(['from_id' => $keepPlace->id]);

        // Merge visit counts
        $keepMetadata = $keepPlace->metadata ?? [];
        $removeMetadata = $removePlace->metadata ?? [];

        $keepMetadata['visit_count'] = ($keepMetadata['visit_count'] ?? 0) + ($removeMetadata['visit_count'] ?? 0);

        // Keep earliest first_visit_at
        if (isset($removeMetadata['first_visit_at'])) {
            if (! isset($keepMetadata['first_visit_at']) ||
                $removeMetadata['first_visit_at'] < $keepMetadata['first_visit_at']) {
                $keepMetadata['first_visit_at'] = $removeMetadata['first_visit_at'];
            }
        }

        $keepPlace->metadata = $keepMetadata;
        $keepPlace->save();

        // Soft delete the removed place
        $removePlace->delete();

        // Refresh the keepPlace model to get the updated relationships
        $keepPlace->refresh();

        Log::info('Merged places', [
            'kept_place_id' => $keepPlace->id,
            'removed_place_id' => $removePlace->id,
            'new_visit_count' => $keepMetadata['visit_count'],
        ]);

        return $keepPlace;
    }

    /**
     * Generate a human-readable title from address
     */
    protected function generatePlaceTitle(?string $address, float $latitude, float $longitude): string
    {
        if (! $address) {
            return sprintf('Location at %s, %s', round($latitude, 4), round($longitude, 4));
        }

        // Try to extract meaningful part from address
        // Examples:
        // "Starbucks, 123 Main St, London" -> "Starbucks"
        // "123 Main Street, London, UK" -> "123 Main Street"
        // "Home, London" -> "Home"

        $parts = array_map('trim', explode(',', $address));

        // If first part looks like a business name (not just a number), use it
        if (! empty($parts[0]) && ! preg_match('/^\d+/', $parts[0])) {
            return $parts[0];
        }

        // If first two parts exist, use them (street address + name)
        if (count($parts) >= 2) {
            return implode(', ', array_slice($parts, 0, 2));
        }

        return $parts[0] ?? 'Unknown Location';
    }

    /**
     * Guess place category from address keywords
     */
    protected function guessCategory(?string $address): ?string
    {
        if (! $address) {
            return null;
        }

        $lower = strtolower($address);

        $categories = [
            'gym' => ['gym', 'fitness', 'crossfit', 'yoga', 'pilates', 'studio'],
            'cafe' => ['cafe', 'coffee', 'starbucks', 'costa', 'pret'],
            'restaurant' => ['restaurant', 'bistro', 'grill', 'kitchen', 'dining', 'pizzeria', 'burger'],
            'bar' => ['bar', 'pub', 'tavern', 'brewery'],
            'shop' => ['shop', 'store', 'market', 'retail', 'mall', 'shopping'],
            'office' => ['office', 'building', 'tower', 'business park'],
            'transport' => ['station', 'airport', 'terminal', 'stop', 'rail'],
            'health' => ['hospital', 'clinic', 'doctor', 'medical', 'dentist', 'pharmacy'],
            'education' => ['school', 'university', 'college', 'library'],
            'entertainment' => ['cinema', 'theater', 'museum', 'gallery', 'park'],
            'hotel' => ['hotel', 'inn', 'lodge', 'resort'],
            'home' => ['home', 'house', 'residence', 'apartment'],
        ];

        foreach ($categories as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return $category;
                }
            }
        }

        return null;
    }
}
