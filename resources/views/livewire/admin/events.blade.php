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
    public bool $selectAll = false;
    public int $perPage = 25;

    protected $queryString = [
        'search' => ['except' => ''],
        'serviceFilter' => ['except' => ''],
        'domainFilter' => ['except' => ''],
        'actionFilter' => ['except' => ''],
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

    public function toggleSelectAll(): void
    {
        if ($this->selectAll) {
            $this->selectedEvents = $this->getEvents()->pluck('id')->toArray();
        } else {
            $this->selectedEvents = [];
        }
    }

    public function toggleEventSelection(string $eventId): void
    {
        if (in_array($eventId, $this->selectedEvents)) {
            $this->selectedEvents = array_filter($this->selectedEvents, fn($id) => $id !== $eventId);
        } else {
            $this->selectedEvents[] = $eventId;
        }

        // Update select all checkbox state
        $this->selectAll = count($this->selectedEvents) === $this->getEvents()->count();
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
            $this->selectAll = false;
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

        return $query->orderBy('time', 'desc')->paginate($this->perPage);
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
                        <x-icon name="o-trash" class="w-4 h-4 mr-1" />
                        Delete Selected ({{ count($selectedEvents) }})
                    </button>
                @endif
            </div>
        </x-slot:actions>
    </x-header>

    <div class="flex flex-col gap-6">
        <!-- Search and Filters -->
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <div class="space-y-4">
                    <!-- Search -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">Search</span>
                        </label>
                        <div class="join w-full">
                            <input
                                type="text"
                                class="input input-bordered join-item flex-1"
                                placeholder="Search by service, domain, action, or object titles..."
                                wire:model.live.debounce.300ms="search"
                            />
                            @if ($search)
                                <button class="btn btn-outline join-item" wire:click="clearFilters">
                                    <x-icon name="o-x-mark" class="w-4 h-4" />
                                </button>
                            @endif
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <!-- Service Filter -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">Service</span>
                            </label>
                            <select class="select select-bordered" wire:model.live="serviceFilter">
                                <option value="">All Services</option>
                                @foreach ($this->getUniqueServices() as $service)
                                    <option value="{{ $service }}">{{ $service }}</option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Domain Filter -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">Domain</span>
                            </label>
                            <select class="select select-bordered" wire:model.live="domainFilter">
                                <option value="">All Domains</option>
                                @foreach ($this->getUniqueDomains() as $domain)
                                    <option value="{{ $domain }}">{{ $domain }}</option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Action Filter -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">Action</span>
                            </label>
                            <select class="select select-bordered" wire:model.live="actionFilter">
                                <option value="">All Actions</option>
                                @foreach ($this->getUniqueActions() as $action)
                                    <option value="{{ $action }}">{{ $this->prettifyAction($action) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <!-- Clear Filters Button -->
                    @if ($serviceFilter || $domainFilter || $actionFilter)
                        <div class="flex justify-end">
                            <button class="btn btn-outline btn-sm" wire:click="clearFilters">
                                <x-icon name="o-x-mark" class="w-4 h-4 mr-1" />
                                Clear Filters
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Events Table -->
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <div class="overflow-x-auto">
                    <table class="table table-zebra">
                        <thead>
                            <tr>
                                <th>
                                    <label class="cursor-pointer">
                                        <input type="checkbox" class="checkbox checkbox-sm"
                                               wire:model="selectAll"
                                               wire:change="toggleSelectAll" />
                                    </label>
                                </th>
                                <th>ID</th>
                                <th>Service</th>
                                <th>Domain</th>
                                <th>Action</th>
                                <th>Target Title</th>
                                <th>Value</th>
                                <th>Blocks</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->getEvents() as $event)
                                <tr>
                                    <td>
                                        <label class="cursor-pointer">
                                            <input type="checkbox" class="checkbox checkbox-sm"
                                                   value="{{ $event->id }}"
                                                   wire:change="toggleEventSelection('{{ $event->id }}')"
                                                   @checked(in_array($event->id, $selectedEvents)) />
                                        </label>
                                    </td>
                                    <td>
                                        <a href="{{ route('events.show', $event->id) }}" class="link link-primary font-mono text-xs" title="{{ $event->id }}">
                                            {{ $this->truncateId($event->id) }}
                                        </a>
                                    </td>
                                    <td>
                                        <span class="badge badge-outline">{{ $event->service }}</span>
                                    </td>
                                    <td>{{ $event->domain }}</td>
                                    <td>{{ $this->prettifyAction($event->action) }}</td>
                                    <td>
                                        @if ($event->target)
                                            <a href="{{ route('objects.show', $event->target->id) }}" class="link link-primary font-medium">
                                                {{ Str::limit($event->target->title, 30) }}
                                            </a>
                                        @else
                                            <span class="text-gray-500">-</span>
                                        @endif
                                    </td>
                                    <td>
                                        {{ $this->formatValue($event->value, $event->value_multiplier, $event->value_unit) }}
                                    </td>
                                    <td>
                                        <span class="badge badge-neutral">{{ $event->blocks_count ?? $event->blocks->count() }}</span>
                                    </td>
                                    <td>
                                        <span class="text-sm">{{ $event->time->format('M j, Y g:i A') }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center py-8">
                                        <div class="text-gray-500">
                                            @if ($search || $serviceFilter || $domainFilter || $actionFilter)
                                                No events found matching your criteria.
                                            @else
                                                No events found.
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="mt-6">
                    {{ $this->getEvents()->links() }}
                </div>
            </div>
        </div>
    </div>
</div>
