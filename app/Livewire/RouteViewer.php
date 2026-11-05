<?php

namespace App\Livewire;

use App\Models\Event;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class RouteViewer extends Component
{
    public Event $event;

    public function mount(Event $event): void
    {
        $this->event = $event;
    }

    #[Computed]
    public function routePoints(): array
    {
        return $this->event->event_metadata['route_points'] ?? [];
    }

    #[Computed]
    public function routeSummary(): array
    {
        return $this->event->event_metadata['route_summary'] ?? [];
    }

    public function render(): View
    {
        return view('livewire.route-viewer');
    }
}
