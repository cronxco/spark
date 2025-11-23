<?php

namespace App\Livewire;

use App\Models\Block;
use App\Models\Event;
use App\Models\EventObject;
use App\Services\RecentlyViewedService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

class RecentlyViewed extends Component
{
    /**
     * Filter by type: 'all', 'events', 'objects', 'blocks'
     */
    public string $typeFilter = 'all';

    /**
     * Number of items to display
     */
    public int $limit = 20;

    /**
     * Whether to show as a compact list or detailed cards
     */
    public string $viewMode = 'list';

    protected $listeners = [
        'refresh-recently-viewed' => '$refresh',
    ];

    /**
     * Get the recently viewed items.
     */
    #[Computed]
    public function items(): Collection
    {
        $user = Auth::user();

        if (!$user) {
            return collect();
        }

        $service = new RecentlyViewedService();

        $types = null;

        if ($this->typeFilter === 'events') {
            $types = [Event::class];
        } elseif ($this->typeFilter === 'objects') {
            $types = [EventObject::class];
        } elseif ($this->typeFilter === 'blocks') {
            $types = [Block::class];
        }

        return $service->getRecentlyViewed($user, $this->limit, $types);
    }

    /**
     * Set the type filter.
     */
    public function setTypeFilter(string $type): void
    {
        $this->typeFilter = $type;
    }

    /**
     * Get the route for a recently viewed item.
     */
    public function getItemRoute(object $item): string
    {
        return match ($item->type) {
            Event::class => route('events.show', $item->id),
            EventObject::class => route('objects.show', $item->id),
            Block::class => route('blocks.show', $item->id),
            default => '#',
        };
    }

    /**
     * Get the icon for a recently viewed item.
     */
    public function getItemIcon(object $item): string
    {
        return match ($item->type) {
            Event::class => 'fas.bolt',
            EventObject::class => 'o-cube',
            Block::class => 'fas.grip',
            default => 'fas.file',
        };
    }

    /**
     * Get the title for a recently viewed item.
     */
    public function getItemTitle(object $item): string
    {
        $model = $item->model;

        return match ($item->type) {
            Event::class => format_action_title($model->action) .
                (should_display_action_with_object($model->action, $model->service)
                    ? ' ' . ($model->target?->title ?? $model->actor?->title ?? '')
                    : ''),
            EventObject::class => $model->title ?? 'Untitled Object',
            Block::class => $model->title ?? $model->block_type ?? 'Untitled Block',
            default => 'Unknown',
        };
    }

    /**
     * Get the subtitle for a recently viewed item.
     */
    public function getItemSubtitle(object $item): string
    {
        $model = $item->model;

        return match ($item->type) {
            Event::class => ($model->service ? ucfirst($model->service) : '') .
                ($model->domain ? ' / ' . ucfirst($model->domain) : ''),
            EventObject::class => ucfirst($model->concept ?? '') .
                ($model->type ? ' / ' . ucfirst($model->type) : ''),
            Block::class => $model->block_type ?? '',
            default => '',
        };
    }

    public function render(): View
    {
        return view('livewire.recently-viewed');
    }
}
