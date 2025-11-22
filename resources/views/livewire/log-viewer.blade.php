<div class="space-y-4 lg:space-y-6">
    <!-- Desktop Filters -->
    <div class="hidden lg:block card bg-base-200 shadow">
        <div class="card-body">
            <div class="flex flex-row gap-4 items-end">
                <div class="form-control">
                    <label class="label"><span class="label-text">Date</span></label>
                    <select wire:model.live="date" class="select select-bordered">
                        @forelse ($availableDates as $availableDate)
                        <option value="{{ $availableDate }}">{{ $availableDate }}</option>
                        @empty
                        <option value="{{ date('Y-m-d') }}">{{ date('Y-m-d') }}</option>
                        @endforelse
                    </select>
                </div>
                <div class="form-control">
                    <label class="label"><span class="label-text">Level</span></label>
                    <select wire:model.live="levelFilter" class="select select-bordered">
                        <option value="all">All Levels</option>
                        <option value="debug">Debug</option>
                        <option value="info">Info</option>
                        <option value="notice">Notice</option>
                        <option value="warning">Warning</option>
                        <option value="error">Error</option>
                        <option value="critical">Critical</option>
                    </select>
                </div>
                <div class="form-control flex-1">
                    <label class="label"><span class="label-text">Search</span></label>
                    <input type="text" class="input input-bordered w-full" placeholder="Search logs..." wire:model.live.debounce.300ms="search" />
                </div>
                @if ($levelFilter !== 'all' || $search)
                <div class="form-control">
                    <button class="btn btn-outline" wire:click="clearFilters">
                        <x-icon name="fas.xmark" class="w-4 h-4" />
                        Clear
                    </button>
                </div>
                @endif
                <div class="form-control">
                    <button wire:click="refreshLogs" class="btn btn-ghost">
                        <x-icon name="fas.rotate" class="w-4 h-4" />
                        Refresh
                    </button>
                </div>
                <div class="form-control">
                    <button wire:click="downloadLog" class="btn btn-primary">
                        <x-icon name="fas.download" class="w-4 h-4" />
                        Download
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Filters -->
    <div class="lg:hidden">
        <x-collapse separator class="bg-base-200">
            <x-slot:heading>
                <div class="flex items-center gap-2">
                    <x-icon name="fas.filter" class="w-5 h-5" />
                    Filters & Actions
                    @if ($levelFilter !== 'all' || $search)
                    <x-badge value="Active" class="badge-primary badge-xs" />
                    @endif
                </div>
            </x-slot:heading>
            <x-slot:content>
                <div class="flex flex-col gap-4">
                    <div class="form-control">
                        <label class="label"><span class="label-text">Date</span></label>
                        <select wire:model.live="date" class="select select-bordered w-full">
                            @forelse ($availableDates as $availableDate)
                            <option value="{{ $availableDate }}">{{ $availableDate }}</option>
                            @empty
                            <option value="{{ date('Y-m-d') }}">{{ date('Y-m-d') }}</option>
                            @endforelse
                        </select>
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text">Level</span></label>
                        <select wire:model.live="levelFilter" class="select select-bordered w-full">
                            <option value="all">All Levels</option>
                            <option value="debug">Debug</option>
                            <option value="info">Info</option>
                            <option value="notice">Notice</option>
                            <option value="warning">Warning</option>
                            <option value="error">Error</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text">Search</span></label>
                        <input type="text" class="input input-bordered w-full" placeholder="Search logs..." wire:model.live.debounce.300ms="search" />
                    </div>
                    @if ($levelFilter !== 'all' || $search)
                    <button class="btn btn-outline" wire:click="clearFilters">
                        <x-icon name="fas.xmark" class="w-4 h-4" />
                        Clear Filters
                    </button>
                    @endif
                    <div class="flex gap-2">
                        <button wire:click="refreshLogs" class="btn btn-ghost flex-1">
                            <x-icon name="fas.rotate" class="w-4 h-4" />
                            Refresh
                        </button>
                        <button wire:click="downloadLog" class="btn btn-primary flex-1">
                            <x-icon name="fas.download" class="w-4 h-4" />
                            Download
                        </button>
                    </div>
                </div>
            </x-slot:content>
        </x-collapse>
    </div>

    <!-- Log entries -->
    <div class="card bg-base-200 shadow-sm">
        <div class="card-body p-0">
            <x-table
                :headers="$this->headers()"
                :rows="$paginatedLogs"
                :sort-by="$sortBy"
                with-pagination
                per-page="perPage"
                :per-page-values="[25, 50, 100, 250]"
                class="[&_table]:!static [&_td]:!static [&_table]:table-xs [&_tbody]:font-mono [&_tbody]:text-xs">

                @scope('cell_timestamp', $log)
                <span class="text-base-content/70">{{ $log['timestamp'] }}</span>
                @endscope

                @scope('cell_level', $log)
                <span class="badge badge-sm {{ $this->getLevelBadgeClass($log['level']) }}">
                    {{ strtoupper($log['level']) }}
                </span>
                @endscope

                @scope('cell_message', $log)
                <span class="whitespace-pre-wrap break-all">{{ $log['message'] }}</span>
                @endscope

                <x-slot:empty>
                    <div class="text-center py-12">
                        <x-icon name="fas.file-lines" class="w-16 h-16 mx-auto text-base-content/30 mb-4" />
                        <h3 class="text-lg font-medium text-base-content mb-2">No Log Entries</h3>
                        <p class="text-base-content/70">
                            @if (empty($search) && $levelFilter === 'all')
                            No logs found for {{ $date }}
                            @else
                            No logs match your current filters
                            @endif
                        </p>
                    </div>
                </x-slot:empty>
            </x-table>
        </div>
    </div>

    @script
    <script>
        // Auto-refresh functionality
        let refreshInterval = null;

        $wire.on('autoRefreshChanged', (enabled) => {
            if (enabled) {
                refreshInterval = setInterval(() => {
                    $wire.refreshLogs();
                }, 5000); // Refresh every 5 seconds
            } else {
                if (refreshInterval) {
                    clearInterval(refreshInterval);
                    refreshInterval = null;
                }
            }
        });
    </script>
    @endscript
</div>