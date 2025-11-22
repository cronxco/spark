<?php

use App\Models\Event;
use App\Models\Block;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;
use Illuminate\Support\Str;
use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component {
    use WithPagination, Toast;

    public string $search = '';
    public string $serviceFilter = '';
    public string $domainFilter = '';
    public string $actionFilter = '';
    public array $selectedEvents = [];
    public int $perPage = 25;
    public array $sortBy = ['column' => 'time', 'direction' => 'desc'];

    protected $queryString = [
        'search' => ['except' => ''],
        'serviceFilter' => ['except' => ''],
        'domainFilter' => ['except' => ''],
        'actionFilter' => ['except' => ''],
        'sortBy' => ['except' => ['column' => 'time', 'direction' => 'desc']],
        'perPage' => ['except' => 25],
        'page' => ['except' => 1],
    ];

    public function mount(): void
    {
        // Initialize any required data
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedServiceFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDomainFilter(): void
    {
        $this->resetPage();
    }

    public function updatedActionFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'serviceFilter', 'domainFilter', 'actionFilter']);
        $this->resetPage();
    }

    public function headers(): array
    {
        return [
            ['key' => 'id', 'label' => 'ID', 'sortable' => false, 'class' => 'hidden sm:table-cell'],
            ['key' => 'service', 'label' => 'Service', 'sortable' => true, 'class' => 'hidden sm:table-cell'],
            ['key' => 'domain', 'label' => 'Domain', 'sortable' => true, 'class' => 'hidden sm:table-cell'],
            ['key' => 'action', 'label' => 'Action', 'sortable' => true],
            ['key' => 'target', 'label' => 'Target', 'sortable' => false],
            ['key' => 'value', 'label' => 'Value', 'sortable' => true, 'class' => 'hidden sm:table-cell'],
            ['key' => 'blocks', 'label' => 'Blocks', 'sortable' => false, 'class' => 'hidden sm:table-cell'],
            ['key' => 'time', 'label' => 'Time', 'sortable' => true, 'class' => 'hidden sm:table-cell'],
        ];
    }

    public function bulkDelete(): void
    {
        if (empty($this->selectedEvents)) {
            $this->error('No events selected for deletion.');
            return;
        }

        try {
            DB::transaction(function () {
                // Delete blocks first (cascade)
                Block::whereIn('event_id', $this->selectedEvents)->delete();

                // Delete events
                Event::whereIn('id', $this->selectedEvents)->delete();
            });

            $count = count($this->selectedEvents);
            $this->success("Successfully deleted {$count} event(s) and their associated blocks.");

            $this->selectedEvents = [];
            $this->resetPage();
        } catch (\Exception $e) {
            $this->error('Failed to delete events: ' . $e->getMessage());
        }
    }

    public function getEvents()
    {
        $query = Event::with(['actor', 'target', 'integration', 'blocks'])
            ->whereHas('integration', function ($q) {
                $q->where('user_id', Auth::id());
            });

        // Apply search filter
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('service', 'ilike', '%' . $this->search . '%')
                    ->orWhere('domain', 'ilike', '%' . $this->search . '%')
                    ->orWhere('action', 'ilike', '%' . $this->search . '%')
                    ->orWhereHas('actor', function ($actorQuery) {
                        $actorQuery->where('title', 'ilike', '%' . $this->search . '%');
                    })
                    ->orWhereHas('target', function ($targetQuery) {
                        $targetQuery->where('title', 'ilike', '%' . $this->search . '%');
                    });
            });
        }

        // Apply service filter
        if ($this->serviceFilter) {
            $query->where('service', $this->serviceFilter);
        }

        // Apply domain filter
        if ($this->domainFilter) {
            $query->where('domain', $this->domainFilter);
        }

        // Apply action filter
        if ($this->actionFilter) {
            $query->where('action', $this->actionFilter);
        }

        // Apply sorting
        $sortColumn = $this->sortBy['column'] ?? 'time';
        $sortDirection = $this->sortBy['direction'] ?? 'desc';
        $query->orderBy($sortColumn, $sortDirection);

        return $query->paginate($this->perPage);
    }

    public function getUniqueServices()
    {
        return Event::whereHas('integration', function ($q) {
            $q->where('user_id', Auth::id());
        })->distinct()->pluck('service')->filter()->sort()->values();
    }

    public function getUniqueDomains()
    {
        return Event::whereHas('integration', function ($q) {
            $q->where('user_id', Auth::id());
        })->distinct()->pluck('domain')->filter()->sort()->values();
    }

    public function getUniqueActions()
    {
        return Event::whereHas('integration', function ($q) {
            $q->where('user_id', Auth::id());
        })->distinct()->pluck('action')->filter()->sort()->values();
    }

    public function truncateId(string $id): string
    {
        return Str::limit($id, 8, '');
    }

    public function formatValue(?int $value, ?int $multiplier, ?string $unit): string
    {
        if ($value === null) {
            return '-';
        }

        $formattedValue = $value;
        if ($multiplier && $multiplier !== 1 && $multiplier !== 0) {
            $formattedValue = $value / $multiplier;
        }

        $result = number_format($formattedValue, 2);
        if ($unit) {
            $result .= ' ' . $unit;
        }

        return $result;
    }

    public function prettifyAction(string $action): string
    {
        return Str::title(str_replace('_', ' ', $action));
    }
};

?>

<div>
    <x-header title="Events Admin" subtitle="Manage and monitor your events data" separator>
        <x-slot:actions>
            <div class="flex items-center gap-2">
                @if (count($selectedEvents) > 0)
                <button class="btn btn-error btn-sm" wire:click="bulkDelete"
                    onclick="return confirm('Are you sure you want to delete {{ count($selectedEvents) }} event(s) and their associated blocks? This action cannot be undone.')">
                    <x-icon name="fas.trash" class="w-4 h-4 mr-1" />
                    Delete Selected ({{ count($selectedEvents) }})
                </button>
                @endif
            </div>
        </x-slot:actions>
    </x-header>

    <div class="space-y-4 lg:space-y-6">
        <!-- Desktop Filters -->
        <div class="hidden lg:block card bg-base-200 shadow">
            <div class="card-body">
                <div class="flex flex-row gap-4">
                    <div class="form-control flex-1">
                        <label class="label"><span class="label-text">Search</span></label>
                        <input type="text" class="input input-bordered w-full" placeholder="Search events..." wire:model.live.debounce.300ms="search" />
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text">Service</span></label>
                        <select class="select select-bordered" wire:model.live="serviceFilter">
                            <option value="">All Services</option>
                            @foreach ($this->getUniqueServices() as $service)
                            <option value="{{ $service }}">{{ $service }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text">Domain</span></label>
                        <select class="select select-bordered" wire:model.live="domainFilter">
                            <option value="">All Domains</option>
                            @foreach ($this->getUniqueDomains() as $domain)
                            <option value="{{ $domain }}">{{ $domain }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text">Action</span></label>
                        <select class="select select-bordered" wire:model.live="actionFilter">
                            <option value="">All Actions</option>
                            @foreach ($this->getUniqueActions() as $action)
                            <option value="{{ $action }}">{{ $this->prettifyAction($action) }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($search || $serviceFilter || $domainFilter || $actionFilter)
                    <div class="form-control content-end">
                        <label class="label"><span class="label-text">&nbsp;</span></label>
                        <button class="btn btn-outline" wire:click="clearFilters">
                            <x-icon name="fas.xmark" class="w-4 h-4" />
                            Clear
                        </button>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Mobile Filters -->
        <div class="lg:hidden">
            <x-collapse separator class="bg-base-200">
                <x-slot:heading>
                    <div class="flex items-center gap-2">
                        <x-icon name="fas.filter" class="w-5 h-5" />
                        Filters
                        @if ($search || $serviceFilter || $domainFilter || $actionFilter)
                        <x-badge value="Active" class="badge-primary badge-xs" />
                        @endif
                    </div>
                </x-slot:heading>
                <x-slot:content>
                    <div class="flex flex-col gap-4">
                        <div class="form-control">
                            <label class="label"><span class="label-text">Search</span></label>
                            <input type="text" class="input input-bordered w-full" placeholder="Search events..." wire:model.live.debounce.300ms="search" />
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">Service</span></label>
                            <select class="select select-bordered w-full" wire:model.live="serviceFilter">
                                <option value="">All Services</option>
                                @foreach ($this->getUniqueServices() as $service)
                                <option value="{{ $service }}">{{ $service }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">Domain</span></label>
                            <select class="select select-bordered w-full" wire:model.live="domainFilter">
                                <option value="">All Domains</option>
                                @foreach ($this->getUniqueDomains() as $domain)
                                <option value="{{ $domain }}">{{ $domain }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">Action</span></label>
                            <select class="select select-bordered w-full" wire:model.live="actionFilter">
                                <option value="">All Actions</option>
                                @foreach ($this->getUniqueActions() as $action)
                                <option value="{{ $action }}">{{ $this->prettifyAction($action) }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if ($search || $serviceFilter || $domainFilter || $actionFilter)
                        <button class="btn btn-outline" wire:click="clearFilters">
                            <x-icon name="fas.xmark" class="w-4 h-4" />
                            Clear Filters
                        </button>
                        @endif
                    </div>
                </x-slot:content>
            </x-collapse>
        </div>

        <!-- Events Table -->
        <div class="card bg-base-200 shadow card-xs sm:card-md">
            <div class="card-body">
                <x-table
                    :headers="$this->headers()"
                    :rows="$this->getEvents()"
                    :sort-by="$sortBy"
                    with-pagination
                    per-page="perPage"
                    :per-page-values="[10, 25, 50, 100]"
                    selectable
                    selectable-key="id"
                    wire:model.live="selectedEvents"
                    link="/events/{id}"
                    striped
                    class="[&_table]:!static [&_td]:!static">
                    <x-slot:empty>
                        <div class="text-center py-12">
                            <x-icon name="fas.calendar" class="w-16 h-16 mx-auto mb-4 text-base-content/70" />
                            <h3 class="text-lg font-medium text-base-content mb-2">No events found</h3>
                            <p class="text-base-content/70">
                                @if ($search || $serviceFilter || $domainFilter || $actionFilter)
                                Try adjusting your filters or search terms
                                @else
                                No events have been recorded yet
                                @endif
                            </p>
                        </div>
                    </x-slot:empty>

                    @scope('cell_id', $event)
                    <span class="text-sm font-mono">{{ $this->truncateId($event->id) }}</span>
                    @endscope

                    @scope('cell_service', $event)
                    <span class="text-sm">{{ $event->service }}</span>
                    @endscope

                    @scope('cell_domain', $event)
                    <span class="text-sm">{{ $event->domain }}</span>
                    @endscope

                    @scope('cell_action', $event)
                    <div class="flex flex-col gap-1">
                        <span class="sm:hidden text-xs">{{ $event->service }}</span>
                        <span class="text-sm">{{ $this->prettifyAction($event->action) }}</span>
                        @if (!empty($event->value))
                        <span class="sm:hidden text-sm">{{ $this->formatValue($event->value, $event->value_multiplier, $event->value_unit) }}</span>
                        @endif
                    </div>
                    @endscope

                    @scope('cell_target', $event)
                    <x-uk-date :date="$event->time" />
                    @if ($event->target)
                    {{ Str::limit($event->target->title, 30) }}
                    @endif
                    @endscope

                    @scope('cell_value', $event)
                    <span class="text-sm">{{ $this->formatValue($event->value, $event->value_multiplier, $event->value_unit) }}</span>
                    @endscope

                    @scope('cell_blocks', $event)
                    <span class="text-sm text-base-content/70">{{ $event->blocks_count ?? $event->blocks->count() }}</span>
                    @endscope

                    @scope('cell_time', $event)
                    <x-uk-date :date="$event->time" />
                    @endscope
                </x-table>
            </div>
        </div>
    </div>
</div>