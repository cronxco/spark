<?php

namespace App\Livewire;

use App\Models\Event;
use App\Models\EventObject;
use App\Services\GeocodingService;
use Clickbar\Magellan\Data\Geometries\Point;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class LocationEditor extends Component
{
    public Model $model;

    public ?string $modelType = null;

    public ?string $locationAddress = null;

    public ?float $latitude = null;

    public ?float $longitude = null;

    public ?string $locationSource = null;

    public bool $isGeocoding = false;

    public ?string $geocodeError = null;

    public bool $showManualCoordinates = false;

    protected $listeners = ['location-updated' => '$refresh'];

    public function mount(Model $model): void
    {
        // Verify ownership
        if ($model instanceof Event) {
            $integration = $model->integration;
            if (! $integration || $integration->user_id !== Auth::id()) {
                abort(403);
            }
        } elseif ($model instanceof EventObject) {
            if ($model->user_id !== Auth::id()) {
                abort(403);
            }
        } else {
            abort(400, 'Invalid model type');
        }

        $this->model = $model;
        $this->modelType = get_class($model);
        $this->locationAddress = $model->location_address;
        $this->latitude = $model->location?->getLatitude();
        $this->longitude = $model->location?->getLongitude();
        $this->locationSource = $model->location_source;
    }

    public function geocode(): void
    {
        $this->geocodeError = null;

        if (! $this->locationAddress) {
            $this->geocodeError = 'Please enter an address';

            return;
        }

        $this->isGeocoding = true;

        try {
            $geocodingService = app(GeocodingService::class);
            $result = $geocodingService->geocode($this->locationAddress);

            if ($result) {
                $this->latitude = $result['latitude'];
                $this->longitude = $result['longitude'];
                $this->locationAddress = $result['formatted_address'] ?? $this->locationAddress;
                $this->locationSource = $result['source'];
            } else {
                $this->geocodeError = 'Could not find location. Try a different address or enter coordinates manually.';
            }
        } catch (Exception $e) {
            $this->geocodeError = 'Geocoding failed: ' . $e->getMessage();
        } finally {
            $this->isGeocoding = false;
        }
    }

    public function save(): void
    {
        $this->validate([
            'locationAddress' => 'nullable|string|max:500',
            'latitude' => 'nullable|numeric|min:-90|max:90',
            'longitude' => 'nullable|numeric|min:-180|max:180',
        ]);

        // If we have coordinates, create the Point
        $location = null;
        if ($this->latitude !== null && $this->longitude !== null) {
            $location = Point::make($this->latitude, $this->longitude, 4326);
        }

        $this->model->update([
            'location_address' => $this->locationAddress,
            'location' => $location,
            'location_geocoded_at' => $location ? now() : null,
            'location_source' => $this->locationSource ?? 'manual',
        ]);

        // Dispatch events
        if ($this->model instanceof Event) {
            $this->dispatch('event-updated');
        } elseif ($this->model instanceof EventObject) {
            $this->dispatch('object-updated');
        }

        $this->dispatch('location-updated');

        $this->notifySuccess('Location updated successfully');
    }

    public function clearLocation(): void
    {
        $this->locationAddress = null;
        $this->latitude = null;
        $this->longitude = null;
        $this->locationSource = null;

        $this->model->update([
            'location_address' => null,
            'location' => null,
            'location_geocoded_at' => null,
            'location_source' => null,
        ]);

        // Dispatch events
        if ($this->model instanceof Event) {
            $this->dispatch('event-updated');
        } elseif ($this->model instanceof EventObject) {
            $this->dispatch('object-updated');
        }

        $this->dispatch('location-updated');

        $this->notifySuccess('Location cleared');
    }

    public function toggleManualCoordinates(): void
    {
        $this->showManualCoordinates = ! $this->showManualCoordinates;
    }

    public function notifySuccess(string $message): void
    {
        $this->js("
            const toast = document.createElement('div');
            toast.className = 'toast toast-top toast-center z-50';
            toast.innerHTML = `
                <div class='alert alert-success shadow-lg'>
                    <svg xmlns='http://www.w3.org/2000/svg' class='stroke-current shrink-0 h-5 w-5' fill='none' viewBox='0 0 24 24'>
                        <path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' />
                    </svg>
                    <span>" . addslashes($message) . "</span>
                </div>
            `;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.classList.add('opacity-0');
                setTimeout(() => toast.remove(), 300);
            }, 2000);
        ");
    }

    public function render()
    {
        return view('livewire.location-editor');
    }
}
