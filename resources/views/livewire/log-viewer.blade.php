<div class="space-y-4">
    <!-- Header with controls -->
    <div class="card bg-base-200 shadow-sm">
        <div class="card-body">
            <div class="flex flex-col lg:flex-row gap-4 items-start lg:items-center justify-between">
                <div class="flex-1 space-y-4 w-full lg:w-auto">
                    <!-- Date selector -->
                    <div class="flex items-center gap-2">
                        <label class="text-sm font-medium">Date:</label>
                        <select wire:model.live="date" class="select select-bordered select-sm">
                            @forelse ($availableDates as $availableDate)
                                <option value="{{ $availableDate }}">{{ $availableDate }}</option>
                            @empty
                                <option value="{{ date('Y-m-d') }}">{{ date('Y-m-d') }}</option>
                            @endforelse
                        </select>
                    </div>

                    <!-- Filters -->
                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Level filter -->
                        <select wire:model.live="levelFilter" class="select select-bordered select-sm">
                            <option value="all">All Levels</option>
                            <option value="debug">Debug</option>
                            <option value="info">Info</option>
                            <option value="notice">Notice</option>
                            <option value="warning">Warning</option>
                            <option value="error">Error</option>
                            <option value="critical">Critical</option>
                        </select>

                        <!-- Search -->
                        <input
                            wire:model.live.debounce.300ms="search"
                            type="text"
                            placeholder="Search logs..."
                            class="input input-bordered input-sm flex-1 min-w-[200px]"
                        />
                    </div>
                </div>

                <!-- Action buttons -->
                <div class="flex gap-2">
                    <button wire:click="refreshLogs" class="btn btn-sm btn-ghost">
                        <x-icon name="o-arrow-path" class="w-4 h-4" />
                        Refresh
                    </button>
                    <button wire:click="downloadLog" class="btn btn-sm btn-primary">
                        <x-icon name="o-arrow-down-tray" class="w-4 h-4" />
                        Download
                    </button>
                </div>
            </div>

            <!-- Log count -->
            <div class="text-sm text-base-content/70 mt-2">
                Showing {{ count($logLines) }} log {{ count($logLines) === 1 ? 'entry' : 'entries' }}
            </div>
        </div>
    </div>

    <!-- Log entries -->
    <div class="card bg-base-200 shadow-sm">
        <div class="card-body p-0">
            @if (count($logLines) > 0)
                <div class="overflow-x-auto">
                    <table class="table table-xs table-pin-rows">
                        <thead>
                            <tr class="bg-base-200">
                                <th class="w-40">Timestamp</th>
                                <th class="w-24">Level</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody class="font-mono text-xs">
                            @foreach ($logLines as $log)
                                <tr class="hover">
                                    <td class="text-base-content/70">{{ $log['timestamp'] }}</td>
                                    <td>
                                        <span class="badge badge-sm {{ $this->getLevelBadgeClass($log['level']) }}">
                                            {{ strtoupper($log['level']) }}
                                        </span>
                                    </td>
                                    <td class="whitespace-pre-wrap break-all">{{ $log['message'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-center py-12">
                    <x-icon name="o-document-text" class="w-16 h-16 mx-auto text-base-content/30 mb-4" />
                    <h3 class="text-lg font-medium text-base-content mb-2">No Log Entries</h3>
                    <p class="text-base-content/70">
                        @if (empty($search) && $levelFilter === 'all')
                            No logs found for {{ $date }}
                        @else
                            No logs match your current filters
                        @endif
                    </p>
                </div>
            @endif
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
