<?php

namespace App\Livewire\Places;

use App\Models\Event;
use App\Models\Place;
use App\Services\PlaceDetectionService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Place')]
class Show extends Component
{
    use WithPagination;

    public Place $place;

    public bool $editing = false;

    // Edit form fields
    public string $editTitle = '';

    public string $editCategory = '';

    public bool $editIsFavorite = false;

    public int $editDetectionRadius = 50;

    public function mount(Place $place): void
    {
        // Ensure user owns this place
        if ($place->user_id !== Auth::id()) {
            abort(403);
        }

        $this->place = $place;
        $this->syncFormFromPlace();
    }

    #[Computed]
    public function eventsAtPlace()
    {
        return $this->place->eventsHere()
            ->with(['target', 'actor', 'integration'])
            ->paginate(20);
    }

    #[Computed]
    public function nearbyEvents()
    {
        // Events within radius but not linked
        $linkedEventIds = $this->place->eventsHere()->pluck('id');

        return $this->place->eventsNearby($this->place->detection_radius)
            ->whereNotIn('id', $linkedEventIds)
            ->with(['target', 'actor', 'integration'])
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'visit_count' => $this->place->visit_count,
            'first_visit_at' => $this->place->first_visit_at,
            'last_visit_at' => $this->place->last_visit_at,
            'linked_events' => $this->place->eventsHere()->count(),
        ];
    }

    public function startEditing(): void
    {
        $this->editing = true;
        $this->syncFormFromPlace();
    }

    public function cancelEditing(): void
    {
        $this->editing = false;
        $this->syncFormFromPlace();
        $this->resetValidation();
    }

    public function savePlace(): void
    {
        $this->validate([
            'editTitle' => 'required|string|max:255',
            'editCategory' => 'nullable|string|max:50',
            'editDetectionRadius' => 'required|integer|min:10|max:500',
        ]);

        $metadata = $this->place->metadata ?? [];
        $metadata['category'] = $this->editCategory ?: null;
        $metadata['is_favorite'] = $this->editIsFavorite;
        $metadata['detection_radius_meters'] = $this->editDetectionRadius;

        $this->place->update([
            'title' => $this->editTitle,
            'metadata' => $metadata,
        ]);

        $this->editing = false;
        $this->dispatch('place-updated', placeId: $this->place->id);
        session()->flash('message', 'Place updated successfully');
    }

    public function toggleFavorite(): void
    {
        $metadata = $this->place->metadata ?? [];
        $metadata['is_favorite'] = ! ($metadata['is_favorite'] ?? false);
        $this->place->metadata = $metadata;
        $this->place->save();

        $this->editIsFavorite = $metadata['is_favorite'];
        $this->dispatch('place-updated', placeId: $this->place->id);
    }

    public function linkNearbyEvent(string $eventId): void
    {
        $event = Event::where('user_id', Auth::id())->findOrFail($eventId);

        $service = app(PlaceDetectionService::class);
        $service->linkEventToPlace($event, $this->place);

        $this->place->recordVisit();
        $this->resetPage();
        $this->dispatch('event-linked', eventId: $eventId);
    }

    public function unlinkEvent(string $eventId): void
    {
        $this->place->relationshipsTo()
            ->where('from_id', $eventId)
            ->where('type', 'occurred_at')
            ->delete();

        $this->resetPage();
        $this->dispatch('event-unlinked', eventId: $eventId);
    }

    public function deletePlace(): void
    {
        $this->place->delete();
        $this->redirect(route('places.index'));
    }

    public function render(): View
    {
        return view('livewire.places.show');
    }

    protected function syncFormFromPlace(): void
    {
        $this->editTitle = $this->place->title;
        $this->editCategory = $this->place->category ?? '';
        $this->editIsFavorite = $this->place->is_favorite;
        $this->editDetectionRadius = $this->place->detection_radius;
    }
}
