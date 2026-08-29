<?php

namespace App\Services\Api;

use App\Models\Event;
use App\Models\EventObject;
use App\Models\User;
use App\Services\GeocodingService;

class LocationMutationService
{
    public function __construct(private EntityMutationService $entities, private GeocodingService $geocoding) {}

    public function set(User $user, string $kind, string $id, float $latitude, float $longitude, ?string $address): Event|EventObject|null
    {
        $entity = $this->entity($user, $kind, $id);
        if ($entity) {
            $entity->setLocation($latitude, $longitude, $address, 'manual');
        }

        return $entity;
    }

    public function clear(User $user, string $kind, string $id): Event|EventObject|null
    {
        $entity = $this->entity($user, $kind, $id);
        if ($entity) {
            $entity->location = null;
            $entity->location_address = null;
            $entity->location_geocoded_at = null;
            $entity->location_source = null;
            $entity->save();
        }

        return $entity;
    }

    public function geocode(User $user, string $kind, string $id, string $address): Event|EventObject|null
    {
        $entity = $this->entity($user, $kind, $id);
        $result = $entity ? $this->geocoding->geocode($address) : null;
        if ($entity && $result) {
            $entity->setLocation((float) $result['latitude'], (float) $result['longitude'], $result['formatted_address'] ?? $address, 'geocoded');
        }

        return $entity;
    }

    private function entity(User $user, string $kind, string $id): Event|EventObject|null
    {
        $entity = $this->entities->find($user, rtrim($kind, 's'), $id);

        return $entity instanceof Event || $entity instanceof EventObject ? $entity : null;
    }
}
