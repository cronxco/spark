<?php

use App\Models\PendingTransactionLink;
use App\Services\RelationshipTypeRegistry;
use App\Services\TransactionLinking\TransactionLinkingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    use Toast, WithPagination;

    public string $search = '';
    public string $statusFilter = 'pending';
    public string $strategyFilter = '';
    public string $confidenceFilter = '';
    public array $selectedLinks = [];
    public int $perPage = 25;
    public array $sortBy = ['column' => 'confidence', 'direction' => 'desc'];

    protected $queryString = [
        'search' => ['except' => ''],
        'statusFilter' => ['except' => 'pending'],
        'strategyFilter' => ['except' => ''],
        'confidenceFilter' => ['except' => ''],
        'sortBy' => ['except' => ['column' => 'confidence', 'direction' => 'desc']],
        'perPage' => ['except' => 25],
        'page' => ['except' => 1],
    ];

    public function mount(): void
    {
        // Initialize
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStrategyFilter(): void
    {
        $this->resetPage();
    }

    public function updatedConfidenceFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter', 'strategyFilter', 'confidenceFilter']);
        $this->statusFilter = 'pending';
        $this->resetPage();
    }

    public function headers(): array
    {
        return [
            ['key' => 'confidence', 'label' => 'Confidence', 'sortable' => true, 'class' => 'w-24'],
            ['key' => 'source', 'label' => 'Source Transaction', 'sortable' => false],
            ['key' => 'type', 'label' => 'Link Type', 'sortable' => false, 'class' => 'w-32'],
            ['key' => 'target', 'label' => 'Target Transaction', 'sortable' => false],
            ['key' => 'strategy', 'label' => 'Strategy', 'sortable' => true, 'class' => 'hidden lg:table-cell'],
            ['key' => 'actions', 'label' => 'Actions', 'sortable' => false, 'class' => 'w-32'],
        ];
    }

    public function approveLink(string $linkId): void
    {
        try {
            $link = PendingTransactionLink::findOrFail($linkId);

            if (!$link->isPending()) {
                $this->warning('This link has already been processed.');
                return;
            }

            $link->approve();
            $this->success('Link approved and relationship created!');
        } catch (\Exception $e) {
            $this->error('Failed to approve link: ' . $e->getMessage());
        }
    }

    public function rejectLink(string $linkId): void
    {
        try {
            $link = PendingTransactionLink::findOrFail($linkId);

            if (!$link->isPending()) {
                $this->warning('This link has already been processed.');
                return;
            }

            $link->reject();
            $this->success('Link rejected.');
        } catch (\Exception $e) {
            $this->error('Failed to reject link: ' . $e->getMessage());
        }
    }

    public function bulkApprove(): void
    {
        if (empty($this->selectedLinks)) {
            $this->error('No links selected.');
            return;
        }

        $approved = 0;
        foreach ($this->selectedLinks as $linkId) {
            try {
                $link = PendingTransactionLink::find($linkId);
                if ($link && $link->isPending()) {
                    $link->approve();
                    $approved++;
                }
            } catch (\Exception $e) {
                // Continue with next
            }
        }

        $this->success("Approved {$approved} link(s).");
        $this->selectedLinks = [];
    }

    public function bulkReject(): void
    {
        if (empty($this->selectedLinks)) {
            $this->error('No links selected.');
            return;
        }

        $rejected = 0;
        foreach ($this->selectedLinks as $linkId) {
            try {
                $link = PendingTransactionLink::find($linkId);
                if ($link && $link->isPending()) {
                    $link->reject();
                    $rejected++;
                }
            } catch (\Exception $e) {
                // Continue with next
            }
        }

        $this->success("Rejected {$rejected} link(s).");
        $this->selectedLinks = [];
    }

    public function getPendingLinks()
    {
        $query = PendingTransactionLink::with(['sourceEvent.actor', 'sourceEvent.target', 'targetEvent.actor', 'targetEvent.target'])
            ->where('user_id', Auth::id());

        // Apply status filter
        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        // Apply strategy filter
        if ($this->strategyFilter) {
            $query->where('detection_strategy', $this->strategyFilter);
        }

        // Apply confidence filter
        if ($this->confidenceFilter) {
            match ($this->confidenceFilter) {
                'high' => $query->where('confidence', '>=', 80),
                'medium' => $query->whereBetween('confidence', [50, 80]),
                'low' => $query->where('confidence', '<', 50),
                default => null,
            };
        }

        // Apply search
        if ($this->search) {
            $query->where(function ($q) {
                $q->whereHas('sourceEvent', function ($eq) {
                    $eq->where('action', 'ilike', '%' . $this->search . '%')
                        ->orWhereRaw("event_metadata::text ilike ?", ['%' . $this->search . '%']);
                })
                ->orWhereHas('targetEvent', function ($eq) {
                    $eq->where('action', 'ilike', '%' . $this->search . '%')
                        ->orWhereRaw("event_metadata::text ilike ?", ['%' . $this->search . '%']);
                });
            });
        }

        // Apply sorting
        $sortColumn = $this->sortBy['column'] ?? 'confidence';
        $sortDirection = $this->sortBy['direction'] ?? 'desc';
        $query->orderBy($sortColumn, $sortDirection);

        return $query->paginate($this->perPage);
    }

    public function getStats(): array
    {
        $linkingService = app(TransactionLinkingService::class);
        return $linkingService->getPendingStats(Auth::id());
    }

    public function getUniqueStrategies()
    {
        return PendingTransactionLink::where('user_id', Auth::id())
            ->distinct()
            ->pluck('detection_strategy')
            ->filter()
            ->sort()
            ->values();
    }

    public function formatTransactionTitle($event): string
    {
        if (!$event) {
            return '-';
        }

        $action = Str::title(str_replace('_', ' ', $event->action));
        $value = $event->value ? '£' . number_format($event->value / ($event->value_multiplier ?? 100), 2) : '';
        $target = $event->target?->title ?? '';

        if ($target) {
            return "{$action} - {$target}" . ($value ? " ({$value})" : '');
        }

        return $action . ($value ? " ({$value})" : '');
    }

    public function formatStrategy(string $strategy): string
    {
        return Str::title(str_replace('_', ' ', $strategy));
    }

    public function getTypeDisplayName(string $type): string
    {
        return RelationshipTypeRegistry::getDisplayName($type) ?? Str::title(str_replace('_', ' ', $type));
    }

    public function getConfidenceBadgeClass(float $confidence): string
    {
        if ($confidence >= 80) {
            return 'badge-success';
        }
        if ($confidence >= 50) {
            return 'badge-warning';
        }
        return 'badge-error';
    }
};

?>

<div>
    <x-header title="Pending Transaction Links" subtitle="Review and approve potential transaction relationships" separator>
        <x-slot:actions>
            <div class="flex items-center gap-2">
                @if (count($selectedLinks) > 0)
                <button class="btn btn-success btn-sm" wire:click="bulkApprove">
                    <x-icon name="o-check" class="w-4 h-4 mr-1" />
                    Approve ({{ count($selectedLinks) }})
                </button>
                <button class="btn btn-error btn-sm" wire:click="bulkReject">
                    <x-icon name="o-x-mark" class="w-4 h-4 mr-1" />
                    Reject ({{ count($selectedLinks) }})
                </button>
                @endif
            </div>
        </x-slot:actions>
    </x-header>

    <div class="space-y-4 lg:space-y-6">
        <!-- Stats Cards -->
        @php $stats = $this->getStats(); @endphp
        <div class="stats stats-vertical lg:stats-horizontal shadow bg-base-200 w-full">
            <div class="stat">
                <div class="stat-figure text-primary">
                    <x-icon name="o-clock" class="w-8 h-8" />
                </div>
                <div class="stat-title">Pending Review</div>
                <div class="stat-value text-primary">{{ $stats['total'] }}</div>
            </div>
            <div class="stat">
                <div class="stat-figure text-success">
                    <x-icon name="o-check-circle" class="w-8 h-8" />
                </div>
                <div class="stat-title">High Confidence</div>
                <div class="stat-value text-success">{{ $stats['by_confidence']['high'] ?? 0 }}</div>
                <div class="stat-desc">≥80% confidence</div>
            </div>
            <div class="stat">
                <div class="stat-figure text-warning">
                    <x-icon name="o-exclamation-triangle" class="w-8 h-8" />
                </div>
                <div class="stat-title">Medium Confidence</div>
                <div class="stat-value text-warning">{{ $stats['by_confidence']['medium'] ?? 0 }}</div>
                <div class="stat-desc">50-80% confidence</div>
            </div>
            <div class="stat">
                <div class="stat-figure text-error">
                    <x-icon name="o-question-mark-circle" class="w-8 h-8" />
                </div>
                <div class="stat-title">Low Confidence</div>
                <div class="stat-value text-error">{{ $stats['by_confidence']['low'] ?? 0 }}</div>
                <div class="stat-desc"><50% confidence</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <div class="flex flex-col lg:flex-row gap-4">
                    <div class="form-control flex-1">
                        <label class="label"><span class="label-text">Search</span></label>
                        <input type="text" class="input input-bordered w-full" placeholder="Search transactions..." wire:model.live.debounce.300ms="search" />
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text">Status</span></label>
                        <select class="select select-bordered" wire:model.live="statusFilter">
                            <option value="">All</option>
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                            <option value="auto_approved">Auto-Approved</option>
                        </select>
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text">Strategy</span></label>
                        <select class="select select-bordered" wire:model.live="strategyFilter">
                            <option value="">All Strategies</option>
                            @foreach ($this->getUniqueStrategies() as $strategy)
                            <option value="{{ $strategy }}">{{ $this->formatStrategy($strategy) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text">Confidence</span></label>
                        <select class="select select-bordered" wire:model.live="confidenceFilter">
                            <option value="">All</option>
                            <option value="high">High (≥80%)</option>
                            <option value="medium">Medium (50-80%)</option>
                            <option value="low">Low (<50%)</option>
                        </select>
                    </div>
                    @if ($search || $statusFilter !== 'pending' || $strategyFilter || $confidenceFilter)
                    <div class="form-control content-end">
                        <label class="label"><span class="label-text">&nbsp;</span></label>
                        <button class="btn btn-outline" wire:click="clearFilters">
                            <x-icon name="o-x-mark" class="w-4 h-4" />
                            Clear
                        </button>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Pending Links Table -->
        <div class="card bg-base-200 shadow">
            <div class="card-body">
                <x-table
                    :headers="$this->headers()"
                    :rows="$this->getPendingLinks()"
                    :sort-by="$sortBy"
                    with-pagination
                    per-page="perPage"
                    :per-page-values="[10, 25, 50, 100]"
                    selectable
                    selectable-key="id"
                    wire:model.live="selectedLinks"
                    striped>
                    <x-slot:empty>
                        <div class="text-center py-12">
                            <x-icon name="o-link" class="w-16 h-16 mx-auto mb-4 text-base-content/70" />
                            <h3 class="text-lg font-medium text-base-content mb-2">No pending links</h3>
                            <p class="text-base-content/70">
                                @if ($search || $statusFilter !== 'pending' || $strategyFilter || $confidenceFilter)
                                Try adjusting your filters
                                @else
                                All transaction links have been reviewed!
                                @endif
                            </p>
                        </div>
                    </x-slot:empty>

                    @scope('cell_confidence', $link)
                    <div class="badge {{ $this->getConfidenceBadgeClass($link->confidence) }}">
                        {{ number_format($link->confidence, 1) }}%
                    </div>
                    @endscope

                    @scope('cell_source', $link)
                    <div class="flex flex-col gap-1">
                        <a href="{{ route('events.show', $link->sourceEvent) }}" class="link link-hover text-sm font-medium">
                            {{ $this->formatTransactionTitle($link->sourceEvent) }}
                        </a>
                        <div class="flex gap-2 text-xs text-base-content/70">
                            <span>{{ $link->sourceEvent?->actor?->title ?? 'Unknown' }}</span>
                            <span>•</span>
                            <span>{{ $link->sourceEvent?->time?->format('M j, Y H:i') }}</span>
                        </div>
                    </div>
                    @endscope

                    @scope('cell_type', $link)
                    <div class="badge badge-outline">
                        {{ $this->getTypeDisplayName($link->relationship_type) }}
                    </div>
                    @endscope

                    @scope('cell_target', $link)
                    <div class="flex flex-col gap-1">
                        <a href="{{ route('events.show', $link->targetEvent) }}" class="link link-hover text-sm font-medium">
                            {{ $this->formatTransactionTitle($link->targetEvent) }}
                        </a>
                        <div class="flex gap-2 text-xs text-base-content/70">
                            <span>{{ $link->targetEvent?->actor?->title ?? 'Unknown' }}</span>
                            <span>•</span>
                            <span>{{ $link->targetEvent?->time?->format('M j, Y H:i') }}</span>
                        </div>
                    </div>
                    @endscope

                    @scope('cell_strategy', $link)
                    <span class="text-sm">{{ $this->formatStrategy($link->detection_strategy) }}</span>
                    @endscope

                    @scope('cell_actions', $link)
                    @if ($link->isPending())
                    <div class="flex gap-1">
                        <button class="btn btn-success btn-xs" wire:click="approveLink('{{ $link->id }}')" title="Approve">
                            <x-icon name="o-check" class="w-4 h-4" />
                        </button>
                        <button class="btn btn-error btn-xs" wire:click="rejectLink('{{ $link->id }}')" title="Reject">
                            <x-icon name="o-x-mark" class="w-4 h-4" />
                        </button>
                    </div>
                    @else
                    <span class="badge badge-ghost badge-sm">
                        {{ ucfirst(str_replace('_', ' ', $link->status)) }}
                    </span>
                    @endif
                    @endscope
                </x-table>
            </div>
        </div>
    </div>
</div>
