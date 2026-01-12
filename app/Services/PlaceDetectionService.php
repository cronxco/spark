<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\Place;
use App\Models\Relationship;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

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
     * Automatically redirects to parent if place has been merged
     */
    public function linkEventToPlace(Event $event, Place $place): Relationship
    {
        // Check if place has been merged, redirect to parent
        $effectivePlace = $place->getEffectivePlace();

        if ($effectivePlace->id !== $place->id) {
            Log::info('Redirecting event link to parent place', [
                'event_id' => $event->id,
                'child_place_id' => $place->id,
                'parent_place_id' => $effectivePlace->id,
            ]);

            $place = $effectivePlace;
        }

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

        // Check if place already exists
        $existingPlace = $this->findNearbyPlace(
            $event->latitude,
            $event->longitude,
            $user,
            $searchRadiusMeters
        );

        $isNewPlace = $existingPlace === null;

        // Detect or create the place
        $place = $this->detectOrCreatePlace(
            $event->latitude,
            $event->longitude,
            $event->location_address,
            $user,
            $searchRadiusMeters
        );

        // Check if this event is already linked to this place
        $existingRelationship = Relationship::where('user_id', $user->id)
            ->where('from_type', Event::class)
            ->where('from_id', $event->id)
            ->where('to_type', EventObject::class)
            ->where('to_id', $place->id)
            ->where('type', 'occurred_at')
            ->first();

        // Only create relationship and record visit if this is a new link
        if (! $existingRelationship) {
            $this->linkEventToPlace($event, $place);

            // Only record visit if this is an existing place
            // New places already have visit_count = 1 from creation
            if (! $isNewPlace) {
                $place->recordVisit();
            }
        }

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
     * Creates a 'merged_into' relationship instead of soft-deleting
     */
    public function mergePlaces(Place $keepPlace, Place $removePlace): Place
    {
        // Prevent self-merge
        if ($keepPlace->id === $removePlace->id) {
            throw new InvalidArgumentException('Cannot merge a place into itself');
        }

        // Prevent circular merges
        if ($keepPlace->isMerged() && $keepPlace->getEffectivePlace()->id === $removePlace->id) {
            throw new InvalidArgumentException('Cannot create circular merge: keep place is already a child of remove place');
        }

        DB::transaction(function () use ($keepPlace, $removePlace) {
            // Redirect any children of removePlace to keepPlace
            Relationship::where('to_id', $removePlace->id)
                ->where('to_type', EventObject::class)
                ->where('from_type', EventObject::class)
                ->where('type', 'merged_into')
                ->update(['to_id' => $keepPlace->id]);

            // Move all 'occurred_at' relationships from removePlace to keepPlace
            Relationship::where('to_id', $removePlace->id)
                ->where('to_type', EventObject::class)
                ->where('type', 'occurred_at')
                ->update(['to_id' => $keepPlace->id]);

            // Merge visit counts and metadata
            $keepMetadata = $keepPlace->metadata ?? [];
            $removeMetadata = $removePlace->metadata ?? [];

            $keepMetadata['visit_count'] =
                ($keepMetadata['visit_count'] ?? 0) +
                ($removeMetadata['visit_count'] ?? 0);

            // Keep earliest first_visit_at
            if (isset($removeMetadata['first_visit_at'])) {
                if (! isset($keepMetadata['first_visit_at']) ||
                    $removeMetadata['first_visit_at'] < $keepMetadata['first_visit_at']) {
                    $keepMetadata['first_visit_at'] = $removeMetadata['first_visit_at'];
                }
            }

            $keepPlace->metadata = $keepMetadata;
            $keepPlace->save();

            // Create 'merged_into' relationship instead of soft-deleting
            Relationship::createRelationship([
                'user_id' => $removePlace->user_id,
                'from_type' => EventObject::class,
                'from_id' => $removePlace->id,
                'to_type' => EventObject::class,
                'to_id' => $keepPlace->id,
                'type' => 'merged_into',
                'metadata' => [
                    'merged_at' => now()->toIso8601String(),
                    'merged_visit_count' => $removeMetadata['visit_count'] ?? 0,
                ],
            ]);
        });

        $keepPlace->refresh();

        Log::info('Merged places', [
            'kept_place_id' => $keepPlace->id,
            'child_place_id' => $removePlace->id,
            'new_visit_count' => $keepPlace->visit_count,
        ]);

        return $keepPlace;
    }

    /**
     * Unmerge a place by removing its 'merged_into' relationship
     */
    public function unmergePlaces(Place $childPlace): Place
    {
        if (! $childPlace->isMerged()) {
            throw new InvalidArgumentException('Place is not merged');
        }

        // Soft delete the 'merged_into' relationship
        $relationship = $childPlace->relationshipsFrom()
            ->where('type', 'merged_into')
            ->first();

        if ($relationship) {
            $relationship->delete();
        }

        Log::info('Unmerged place', [
            'place_id' => $childPlace->id,
            'former_parent_id' => $relationship?->to_id,
        ]);

        return $childPlace->refresh();
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
