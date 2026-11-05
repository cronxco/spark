<?php

namespace App\Livewire\Places;

use App\Models\Place;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Places')]
class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public ?string $categoryFilter = null;

    public bool $favoritesOnly = false;

    public string $sortBy = 'visits'; // 'visits', 'recent', 'name'

    public function mount(): void
    {
        //
    }

    #[Computed]
    public function places()
    {
        $query = Place::query()
            ->where('user_id', Auth::id())
            ->with(['tags']);

        // Search filter
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('title', 'ilike', "%{$this->search}%")
                    ->orWhere('location_address', 'ilike', "%{$this->search}%");
            });
        }

        // Category filter
        if ($this->categoryFilter) {
            $query->byCategory($this->categoryFilter);
        }

        // Favorites filter
        if ($this->favoritesOnly) {
            $query->favorites();
        }

        // Sorting
        match ($this->sortBy) {
            'visits' => $query->orderByVisitCount('desc'),
            'recent' => $query->orderByRaw("metadata->>'last_visit_at' DESC NULLS LAST"),
            'name' => $query->orderBy('title'),
            default => $query->orderByVisitCount('desc'),
        };

        return $query->paginate(24);
    }

    #[Computed]
    public function categories(): array
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
    public function stats(): array
    {
        $places = Place::where('user_id', Auth::id());

        return [
            'total_places' => $places->count(),
            'favorites' => (clone $places)->favorites()->count(),
            'total_visits' => (clone $places)->get()->sum('visit_count'),
        ];
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'categoryFilter', 'favoritesOnly', 'sortBy']);
        $this->resetPage();
    }

    public function toggleFavorite(string $placeId): void
    {
        $place = Place::where('user_id', Auth::id())->findOrFail($placeId);

        $metadata = $place->metadata ?? [];
        $metadata['is_favorite'] = ! ($metadata['is_favorite'] ?? false);
        $place->metadata = $metadata;
        $place->save();

        $this->dispatch('place-updated', placeId: $placeId);
    }

    public function deletePlace(string $placeId): void
    {
        $place = Place::where('user_id', Auth::id())->findOrFail($placeId);
        $place->delete();

        $this->dispatch('place-deleted', placeId: $placeId);
        $this->resetPage();
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'categoryFilter', 'favoritesOnly', 'sortBy'])) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        return view('livewire.places.index');
    }
}
