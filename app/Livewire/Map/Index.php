<?php

namespace App\Livewire\Map;

use App\Integrations\PluginRegistry;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Place;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Map')]
class Index extends Component
{
    use WithPagination;

    public array $selectedServices = [];

    public ?string $startDate = null;

    public ?string $endDate = null;

    public string $viewType = 'events'; // 'events' or 'objects'

    public string $viewMode = 'map'; // 'map' or 'places'

    // Places view properties
    public string $placesSearch = '';

    public ?string $placesCategoryFilter = null;

    public bool $placesFavoritesOnly = false;

    public string $placesSortBy = 'visits'; // 'visits', 'recent', 'name'

    // Timeline view properties
    public string $timelineGrouping = 'day'; // 'hour', 'day', 'week'

    public bool $showJourneyRoutes = true;

    public function mount(): void
    {
        // Default to last 30 days
        $this->startDate = now()->subDays(30)->format('Y-m-d');
        $this->endDate = now()->format('Y-m-d');
    }

    #[Computed]
    public function events()
    {
        $query = Event::query()
            ->whereNotNull('location')
            ->whereHas('integration', fn ($q) => $q->where('user_id', Auth::id()))
            ->with(['target', 'integration', 'actor'])
            ->orderByDesc('time');

        if ($this->startDate) {
            $query->where('time', '>=', Carbon::parse($this->startDate)->startOfDay());
        }

        if ($this->endDate) {
            $query->where('time', '<=', Carbon::parse($this->endDate)->endOfDay());
        }

        if (! empty($this->selectedServices)) {
            $query->where(function ($q) {
                foreach ($this->selectedServices as $service) {
                    $q->orWhere('service', $service);
                }
            });
        }

        return $query->get();
    }

    #[Computed]
    public function objects()
    {
        $query = EventObject::query()
            ->whereNotNull('location')
            ->where('user_id', Auth::id())
            ->orderByDesc('time');

        if ($this->startDate) {
            $query->where('time', '>=', Carbon::parse($this->startDate)->startOfDay());
        }

        if ($this->endDate) {
            $query->where('time', '<=', Carbon::parse($this->endDate)->endOfDay());
        }

        return $query->get();
    }

    #[Computed]
    public function availableServices(): array
    {
        $services = Event::query()
            ->whereNotNull('location')
            ->whereHas('integration', fn ($q) => $q->where('user_id', Auth::id()))
            ->distinct()
            ->pluck('service')
            ->toArray();

        return array_map(function ($service) {
            $pluginClass = PluginRegistry::getPlugin($service);

            return [
                'value' => $service,
                'label' => $pluginClass ? $pluginClass::getDisplayName() : $service,
                'icon' => $pluginClass ? $pluginClass::getIcon() : 'fas.circle',
            ];
        }, $services);
    }

    public function getMapData(): array
    {
        $items = $this->viewType === 'events' ? $this->events : $this->objects;

        return $items->map(function ($item) {
            if (! $item->location) {
                return null;
            }

            $pluginClass = $item instanceof Event
                ? PluginRegistry::getPlugin($item->service)
                : null;

            return [
                'id' => $item->id,
                'type' => $item instanceof Event ? 'event' : 'object',
                'latitude' => $item->latitude,
                'longitude' => $item->longitude,
                'title' => $item instanceof Event
                    ? ($item->target->title ?? 'Event')
                    : $item->title,
                'address' => $item->location_address,
                'time' => $item->time?->toIso8601String(),
                'service' => $item instanceof Event ? $item->service : null,
                'icon' => $pluginClass ? $pluginClass::getIcon() : 'fas.map-marker',
                'url' => $item instanceof Event
                    ? route('events.show', $item)
                    : route('objects.show', $item),
                'popup_html' => $this->getPopupHtml($item),
            ];
        })->filter()->values()->toArray();
    }

    public function clearFilters(): void
    {
        $this->selectedServices = [];
        $this->startDate = now()->subDays(30)->format('Y-m-d');
        $this->endDate = now()->format('Y-m-d');
        $this->dispatch('map-filters-updated');
    }

    public function updated($property): void
    {
        // Dispatch event when any filter property changes
        if (in_array($property, ['selectedServices', 'startDate', 'endDate', 'viewType', 'timelineGrouping', 'showJourneyRoutes'])) {
            $this->dispatch('map-filters-updated');
        }
    }

    #[Computed]
    public function places()
    {
        $query = Place::query()
            ->where('user_id', Auth::id())
            ->with(['tags']);

        // Search filter
        if ($this->placesSearch) {
            $query->where(function ($q) {
                $q->where('title', 'ilike', "%{$this->placesSearch}%")
                    ->orWhere('location_address', 'ilike', "%{$this->placesSearch}%");
            });
        }

        // Category filter
        if ($this->placesCategoryFilter) {
            $query->byCategory($this->placesCategoryFilter);
        }

        // Favorites filter
        if ($this->placesFavoritesOnly) {
            $query->favorites();
        }

        // Sorting
        match ($this->placesSortBy) {
            'visits' => $query->orderByVisitCount('desc'),
            'recent' => $query->orderByRaw("metadata->>'last_visit_at' DESC NULLS LAST"),
            'name' => $query->orderBy('title'),
            default => $query->orderByVisitCount('desc'),
        };

        return $query->paginate(24);
    }

    #[Computed]
    public function placesCategories(): array
    {
        $categories = Place::query()
            ->where('user_id', Auth::id())
            ->whereNotNull('metadata->category')
            ->selectRaw("metadata->>'category' as category, COUNT(*) as count")
            ->groupBy('category')
            ->orderBy('count', 'desc')
            ->get()
            ->map(fn ($row) => [
                'value' => $row->category,
                'label' => ucfirst($row->category),
                'count' => $row->count,
            ])
            ->toArray();

        return $categories;
    }

    #[Computed]
    public function timelineData()
    {
        $events = Event::query()
            ->whereNotNull('location')
            ->whereHas('integration', fn ($q) => $q->where('user_id', Auth::id()))
            ->with(['integration', 'actor', 'target'])
            ->orderBy('time', 'asc'); // Chronological order

        if ($this->startDate) {
            $events->where('time', '>=', Carbon::parse($this->startDate)->startOfDay());
        }

        if ($this->endDate) {
            $events->where('time', '<=', Carbon::parse($this->endDate)->endOfDay());
        }

        if (! empty($this->selectedServices)) {
            $events->where(function ($q) {
                foreach ($this->selectedServices as $service) {
                    $q->orWhere('service', $service);
                }
            });
        }

        $events = $events->get();

        return $events->groupBy(function ($event) {
            return match ($this->timelineGrouping) {
                'hour' => $event->time->format('Y-m-d H:00'),
                'day' => $event->time->format('Y-m-d'),
                'week' => $event->time->startOfWeek()->format('Y-m-d'),
                default => $event->time->format('Y-m-d'),
            };
        });
    }

    #[Computed]
    public function journeyRoutes(): array
    {
        if (! $this->showJourneyRoutes) {
            return [];
        }

        $events = Event::query()
            ->whereNotNull('location')
            ->whereHas('integration', fn ($q) => $q->where('user_id', Auth::id()))
            ->orderBy('time', 'asc');

        if ($this->startDate) {
            $events->where('time', '>=', Carbon::parse($this->startDate)->startOfDay());
        }

        if ($this->endDate) {
            $events->where('time', '<=', Carbon::parse($this->endDate)->endOfDay());
        }

        if (! empty($this->selectedServices)) {
            $events->where(function ($q) {
                foreach ($this->selectedServices as $service) {
                    $q->orWhere('service', $service);
                }
            });
        }

        $events = $events->get();
        $routes = [];
        $previousEvent = null;

        foreach ($events as $event) {
            if ($previousEvent && $event->latitude && $event->longitude) {
                $routes[] = [
                    'from' => [
                        'lat' => $previousEvent->latitude,
                        'lng' => $previousEvent->longitude,
                    ],
                    'to' => [
                        'lat' => $event->latitude,
                        'lng' => $event->longitude,
                    ],
                    'time_gap_minutes' => $previousEvent->time->diffInMinutes($event->time),
                ];
            }
            $previousEvent = $event;
        }

        return $routes;
    }

    #[Computed]
    public function placesStats(): array
    {
        $places = Place::where('user_id', Auth::id());

        return [
            'total_places' => $places->count(),
            'favorites' => (clone $places)->favorites()->count(),
            'total_visits' => (clone $places)->get()->sum('visit_count'),
        ];
    }

    public function togglePlaceFavorite(string $placeId): void
    {
        $place = Place::where('user_id', Auth::id())->findOrFail($placeId);

        $metadata = $place->metadata ?? [];
        $metadata['is_favorite'] = ! ($metadata['is_favorite'] ?? false);
        $place->metadata = $metadata;
        $place->save();

        $this->dispatch('place-updated', placeId: $placeId);
    }

    public function clearPlacesFilters(): void
    {
        $this->reset(['placesSearch', 'placesCategoryFilter', 'placesFavoritesOnly', 'placesSortBy']);
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.map.index');
    }

    protected function getPopupHtml($item): string
    {
        if ($item instanceof Event) {
            return view('livewire.map.popup-event', ['event' => $item])->render();
        }

        return view('livewire.map.popup-object', ['object' => $item])->render();
    }
}
