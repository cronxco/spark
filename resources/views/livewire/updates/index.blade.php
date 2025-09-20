<?php
use App\Jobs\ProcessIntegrationData;
use App\Jobs\RunIntegrationTask;
use App\Models\Integration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Mary\Traits\Toast;
use Carbon\Carbon;

new class extends Component {
    use Toast;

    public array $integrations = [];
    public bool $isRefreshing = false;
    public string $filter = 'all'; // all|integrations|tasks
    public string $search = '';
    public array $collapsedPlugins = [];

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'integrations', 'tasks']) ? $filter : 'all';
        $this->loadData();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->loadData();
    }

    public function togglePluginCollapse(string $pluginName): void
    {
        if (in_array($pluginName, $this->collapsedPlugins)) {
            $this->collapsedPlugins = array_filter($this->collapsedPlugins, fn($p) => $p !== $pluginName);
        } else {
            $this->collapsedPlugins[] = $pluginName;
        }
    }

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $query = $user->integrations()
            ->with('user')
            ->orderBy('last_successful_update_at', 'asc')
            ->orderBy('created_at', 'asc')
            ;

        if ($this->filter === 'integrations') {
            $query->where(function ($q) {
                $q->whereNull('instance_type')->orWhere('instance_type', '!=', 'task');
            })->where('service', '!=', 'task');
        } elseif ($this->filter === 'tasks') {
            $query->where(function ($q) {
                $q->where('instance_type', 'task')->orWhere('service', 'task');
            });
        }

        // Apply search filter
        if (!empty($this->search)) {
            $query->where(function ($q) {
                $q->where('name', 'ILIKE', '%' . $this->search . '%')
                  ->orWhere('service', 'ILIKE', '%' . $this->search . '%');
            });
        }

        $userIntegrations = $query->get();

        $integrationsData = $userIntegrations->map(function ($integration) {
            $batchName = null;
            $batchProgress = null;
            $migrationPhase = null;
            if (!empty($integration->migration_batch_id)) {
                try {
                    $batch = Bus::findBatch($integration->migration_batch_id);
                    if ($batch) {
                        $batchName = $batch->name;
                        $batchProgress = $batch->progress();
                        if (str_starts_with((string) $batchName, 'monzo_fetch_')) {
                            $migrationPhase = 'fetch';
                        } elseif (str_starts_with((string) $batchName, 'monzo_process_')) {
                            $migrationPhase = 'process';
                        }
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            // Monzo migration hints from cache (fetched back to date and tx window count)
            $fetchedBackTo = null;
            $txWindowCount = null;
            if ($integration->service === 'monzo') {
                // Prefer the generic fetched_back_to (from transactions windows); fallback to balances marker
                $fetchedBackTo = Cache::get('monzo:migration:' . $integration->id . ':fetched_back_to')
                    ?: Cache::get('monzo:migration:' . $integration->id . ':balances_last_date');
                $windows = Cache::get('monzo:migration:' . $integration->id . ':tx_windows');
                if (is_array($windows)) {
                    $txWindowCount = count($windows);
                }
            }

            // Sweep status (service-specific)
            $cfg = $integration->configuration ?? [];
            $service = (string) $integration->service;
            $sweepEnabled = false;
            $sweepLabel = null;
            $sweepWindow = null;
            $sweepLastAt = null;
            $sweepNextAt = null;
            $sweepPercent = null;

            try {
                $now = Carbon::now();
                $periodHours = null;

                if ($service === 'monzo') {
                    $sweepEnabled = true;
                    $sweepLabel = 'Daily sweep';
                    $sweepWindow = 'last 30 days';
                    $sweepLastAt = $cfg['monzo_last_sweep_at'] ?? null;
                    $periodHours = 24;
                } elseif ($service === 'spotify') {
                    $sweepEnabled = true;
                    $sweepLabel = 'Daily sweep';
                    $sweepWindow = 'last 36 hours';
                    $sweepLastAt = $cfg['spotify_last_sweep_at'] ?? null;
                    $periodHours = 24;
                } elseif ($service === 'hevy') {
                    $sweepEnabled = true;
                    $sweepLabel = 'Weekly sweep';
                    $sweepWindow = 'last 30 days';
                    $sweepLastAt = $cfg['hevy_last_sweep_at'] ?? null;
                    $periodHours = 24 * 7;
                } elseif ($service === 'oura') {
                    $sweepEnabled = true;
                    $sweepLabel = 'Daily sweep';
                    $sweepWindow = 'last 30 days';
                    $sweepLastAt = $cfg['oura_last_sweep_at'] ?? null;
                    $periodHours = 24;
                } elseif ($service === 'gocardless') {
                    $sweepEnabled = true;
                    $sweepLabel = 'Weekly sweep';
                    $sweepWindow = 'last 60 days';
                    $sweepLastAt = $cfg['gocardless_last_sweep_at'] ?? null;
                    $periodHours = 24 * 7;
                }

                if ($sweepEnabled) {
                    if ($sweepLastAt) {
                        $last = Carbon::parse($sweepLastAt);
                        $sweepNextAt = $last->copy()->addHours($periodHours)->toIso8601String();
                        $elapsed = max(0, $now->diffInSeconds($last, false));
                        $periodSec = $periodHours * 3600;
                        $sweepPercent = (int) max(0, min(100, round(($elapsed / $periodSec) * 100)));
                    } else {
                        $sweepPercent = 0;
                    }
                }
            } catch (\Throwable $e) {
                // leave sweep values null on error
            }

            return [
                'id' => $integration->id,
                'name' => $integration->name ?: $integration->service,
                'service' => $integration->service,
                'instance_type' => $integration->instance_type,
                'account_id' => $integration->account_id,
                'update_frequency_minutes' => $integration->getUpdateFrequencyMinutes(),
                'last_triggered_at' => $integration->last_triggered_at ? $integration->last_triggered_at->toISOString() : null,
                'last_successful_update_at' => $integration->last_successful_update_at ? $integration->last_successful_update_at->toISOString() : null,
                'needs_update' => $integration->needsUpdate(),
                'next_update_time' => $integration->getNextUpdateTime() ? $integration->getNextUpdateTime()->toISOString() : null,
                'is_processing' => $integration->isProcessing(),
                'status' => $this->getIntegrationStatus($integration),
                'is_paused' => $integration->isPaused(),
                'schedule_summary' => $integration->getScheduleSummary(),
                'migration_progress' => $batchProgress,
                'migration_batch_id' => $integration->migration_batch_id,
                'migration_batch_name' => $batchName,
                'migration_phase' => $migrationPhase,
                'migration_fetched_back_to' => $fetchedBackTo,
                'migration_tx_window_count' => $txWindowCount,
                'sweep_enabled' => $sweepEnabled,
                'sweep_label' => $sweepLabel,
                'sweep_window' => $sweepWindow,
                'sweep_last_at' => $sweepLastAt,
                'sweep_next_at' => $sweepNextAt,
                'sweep_percent' => $sweepPercent,
            ];
        })->toArray();

        // Group integrations by plugin and integration group
        $groupedIntegrations = [];
        foreach ($integrationsData as $integration) {
            $pluginClass = \App\Integrations\PluginRegistry::getPlugin($integration['service']);
            $pluginName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($integration['service']);
            $pluginIcon = $pluginClass ? $pluginClass::getIcon() : 'fas.puzzle-piece';
            
            // Get integration group info
            $integrationModel = $userIntegrations->firstWhere('id', $integration['id']);
            $groupName = $integrationModel && $integrationModel->group ? 
                ($integrationModel->group->account_id ?: 'Default Group') : 
                'Default Group';
            
            if (!isset($groupedIntegrations[$pluginName])) {
                $groupedIntegrations[$pluginName] = [
                    'plugin_name' => $pluginName,
                    'plugin_icon' => $pluginIcon,
                    'plugin_service' => $integration['service'],
                    'groups' => []
                ];
            }
            
            if (!isset($groupedIntegrations[$pluginName]['groups'][$groupName])) {
                $groupedIntegrations[$pluginName]['groups'][$groupName] = [
                    'group_name' => $groupName,
                    'integrations' => []
                ];
            }
            
            $groupedIntegrations[$pluginName]['groups'][$groupName]['integrations'][] = $integration;
        }

        $this->integrations = $groupedIntegrations;
    }

    private function getIntegrationStatus(Integration $integration): string
    {
        if ($integration->isProcessing()) {
            return 'processing';
        }

        if ($integration->needsUpdate()) {
            return 'needs_update';
        }

        return 'up_to_date';
    }

    public function triggerUpdate(string $integrationId): void
    {
        $integration = Integration::find($integrationId);

        if (!$integration) {
            $this->error('Integration not found.');
            return;
        }

        if ((string) $integration->user_id !== (string) Auth::id()) {
            $this->error('Integration not found.');
            return;
        }

        try {
            // Dispatch the job based on instance type
            if ($integration->instance_type === 'task' || $integration->service === 'task') {
                RunIntegrationTask::dispatch($integration)
                    ->onQueue($integration->configuration['task_queue'] ?? 'pull');
            } else {
                ProcessIntegrationData::dispatch($integration);
            }

            $this->success('Update triggered successfully!');
            $this->loadData();

        } catch (\Exception $e) {
            $this->error('Failed to trigger update. Please try again.');
        }
    }

    public function togglePause(string $integrationId): void
    {
        $integration = Integration::find($integrationId);
        if (!$integration || (string) $integration->user_id !== (string) Auth::id()) {
            $this->error('Integration not found.');
            return;
        }

        $config = $integration->configuration ?? [];
        $config['paused'] = !((bool) ($config['paused'] ?? false));
        $integration->update(['configuration' => $config]);
        $this->success($config['paused'] ? 'Paused.' : 'Resumed.');
        $this->loadData();
    }

    public function refreshData(): void
    {
        $this->isRefreshing = true;
        $this->loadData();
        $this->isRefreshing = false;
    }

    public function getPollingInterval(): int
    {
        return 5000; // 5 seconds
    }
}; ?>

<div wire:poll.5s="refreshData">
    <div class="flex flex-col gap-6">
        <!-- Header -->
        <div class="flex flex-col gap-4">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-bold text-base-content">Updates</h1>
                    <p class="text-base-content/70">Monitor and manage your integration data updates</p>
                </div>
                <div class="flex items-center gap-2">
                    <div class="join">
                        <button class="btn btn-sm join-item {{ $filter === 'all' ? 'btn-primary' : 'btn-outline' }}" wire:click="setFilter('all')">All</button>
                        <button class="btn btn-sm join-item {{ $filter === 'integrations' ? 'btn-primary' : 'btn-outline' }}" wire:click="setFilter('integrations')">Integrations</button>
                        <button class="btn btn-sm join-item {{ $filter === 'tasks' ? 'btn-primary' : 'btn-outline' }}" wire:click="setFilter('tasks')">Tasks</button>
                    </div>
                </div>
            </div>
            
            <!-- Search Input -->
            <div class="flex items-center gap-2">
                <div class="relative flex-1 max-w-md">
                    <input 
                        type="text" 
                        placeholder="Search by plugin name or integration name..." 
                        class="input input-bordered w-full pr-10 text-sm sm:text-base" 
                        wire:model.live.debounce.300ms="search"
                    />
                    @if($search)
                        <button 
                            class="absolute right-2 top-1/2 transform -translate-y-1/2 btn btn-ghost btn-xs"
                            wire:click="clearSearch"
                        >
                            <x-icon name="o-x-mark" class="w-4 h-4" />
                        </button>
                    @else
                        <x-icon name="o-magnifying-glass" class="absolute right-3 top-1/2 transform -translate-y-1/2 w-4 h-4 text-base-content/50" />
                    @endif
                </div>
            </div>
            @if ($isRefreshing)
                <div class="flex items-center space-x-2">
                    <x-loading class="loading-spinner loading-sm" />
                    <span class="text-sm text-base-content/70">{{ __('Refreshing...') }}</span>
                </div>
            @endif
        </div>

        <!-- Integrations List -->
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                @if (count($integrations) === 0)
                    <div class="text-center py-12">
                        <x-icon name="fas.inbox" class="w-16 h-16 text-base-content/30 mx-auto mb-4" />
                        <h3 class="text-lg font-semibold mb-2">{{ __('No Integrations Found') }}</h3>
                        <p class="text-base-content/70 mb-4">{{ __('You haven\'t set up any integrations yet.') }}</p>
                        <x-button
                            label="{{ __('Go to Integrations') }}"
                            icon="fas.puzzle-piece"
                            link="{{ route('integrations.index') }}"
                            class="btn-primary"
                        />
                    </div>
                @else
                    <div class="space-y-6">
                        @foreach ($integrations as $pluginName => $pluginData)
                            @php
                                $isCollapsed = in_array($pluginName, $collapsedPlugins);
                                $totalInstances = collect($pluginData['groups'])->sum(fn($group) => count($group['integrations']));
                                $needsUpdateCount = collect($pluginData['groups'])
                                    ->flatten()
                                    ->filter(fn($item) => isset($item['integrations']))
                                    ->flatten()
                                    ->where('status', 'needs_update')
                                    ->count();
                                $processingCount = collect($pluginData['groups'])
                                    ->flatten()
                                    ->filter(fn($item) => isset($item['integrations']))
                                    ->flatten()
                                    ->where('is_processing', true)
                                    ->count();
                            @endphp
                            
                            <!-- Plugin Header -->
                            <div class="card bg-base-100 shadow-sm">
                                <div class="card-body p-3 sm:p-4">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center space-x-2 sm:space-x-3 min-w-0 flex-1">
                                            <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-lg bg-base-200 flex items-center justify-center flex-shrink-0">
                                                <x-icon :name="$pluginData['plugin_icon']" class="w-5 h-5 sm:w-6 sm:h-6 text-base-content" />
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <h3 class="text-lg sm:text-xl font-semibold truncate">{{ $pluginName }}</h3>
                                                <div class="flex flex-col sm:flex-row sm:items-center sm:space-x-4 text-xs sm:text-sm text-base-content/70 space-y-1 sm:space-y-0">
                                                    <span>{{ $totalInstances }} {{ Str::plural('instance', $totalInstances) }}</span>
                                                    @if($needsUpdateCount > 0)
                                                        <span class="text-error">{{ $needsUpdateCount }} need{{ $needsUpdateCount === 1 ? 's' : '' }} update</span>
                                                    @endif
                                                    @if($processingCount > 0)
                                                        <span class="text-info">{{ $processingCount }} processing</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                        <button 
                                            class="btn btn-ghost btn-sm flex-shrink-0"
                                            wire:click="togglePluginCollapse('{{ $pluginName }}')"
                                        >
                                            <x-icon :name="$isCollapsed ? 'o-chevron-right' : 'o-chevron-down'" class="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Integration Groups -->
                            @if(!$isCollapsed)
                                <div class="ml-2 sm:ml-6 space-y-4">
                                    @foreach ($pluginData['groups'] as $groupName => $groupData)
                                        <div class="space-y-3">
                                            @if(count($pluginData['groups']) > 1)
                                                <div class="flex items-center space-x-2 text-xs sm:text-sm text-base-content/70">
                                                    <x-icon name="o-folder" class="w-3 h-3 sm:w-4 sm:h-4 flex-shrink-0" />
                                                    <span class="font-medium truncate">{{ $groupName }}</span>
                                                    <span class="text-xs flex-shrink-0">({{ count($groupData['integrations']) }} {{ Str::plural('instance', count($groupData['integrations'])) }})</span>
                                                </div>
                                            @endif
                                            
                                            <div class="space-y-3">
                                                @foreach ($groupData['integrations'] as $integration)
                                                    @php
                                                        // Determine card border color based on status
                                                        $cardBorderClass = 'border-l-4 ';
                                                        if ($integration['is_processing']) {
                                                            $cardBorderClass .= 'border-info';
                                                        } elseif ($integration['is_paused']) {
                                                            $cardBorderClass .= 'border-neutral';
                                                        } elseif ($integration['status'] === 'needs_update') {
                                                            $cardBorderClass .= 'border-error';
                                                        } elseif ($integration['status'] === 'up_to_date') {
                                                            $cardBorderClass .= 'border-success';
                                                        } else {
                                                            $cardBorderClass .= 'border-base-300';
                                                        }
                                                    @endphp
                                                    <div class="card bg-base-200 shadow-sm {{ $cardBorderClass }}">
                                <div class="card-body p-3 sm:p-4">
                                    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between mb-4 space-y-3 sm:space-y-0">
                                        <div class="flex items-center space-x-2 sm:space-x-3 min-w-0 flex-1">
                                            <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg bg-base-300 flex items-center justify-center flex-shrink-0">
                                                @php
                                                    $pluginClass = \App\Integrations\PluginRegistry::getPlugin($integration['service']);
                                                    $icon = $pluginClass ? $pluginClass::getIcon() : 'fas.puzzle-piece';
                                                @endphp
                                                <x-icon :name="$icon" class="w-4 h-4 sm:w-5 sm:h-5 text-base-content" />
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <h4 class="text-base sm:text-lg font-semibold">
                                                    <a href="{{ route('integrations.details', $integration['id']) }}" class="hover:text-primary transition-colors truncate block">
                                                        {{ $integration['name'] }}
                                                    </a>
                                                </h4>
                                                <div class="text-xs sm:text-sm text-base-content/70 space-y-1">
                                                    <div class="truncate">
                                                        {{ \Illuminate\Support\Str::of($integration['service'])->replace('_', ' ')->title() }}
                                                    </div>
                                                    @if ($integration['service'] === 'monzo'
                                                    && !is_null($integration['migration_progress'])
                                                    && $integration['migration_progress'] < 100
                                                    && $integration['migration_fetched_back_to'])
                                                        <div class="text-xs text-base-content/70 truncate">
                                                            {{ __('Fetched to') }}: {{ $integration['migration_fetched_back_to'] }}
                                                        </div>
                                                    @endif
                                                    @if ($integration['account_id'])
                                                        <div class="text-xs text-base-content/70 truncate">
                                                            {{ __('Account') }}: {{ $integration['account_id'] }}
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>

                                        @php
                                            $pluginClass = \App\Integrations\PluginRegistry::getPlugin($integration['service']);
                                            $isWebhook = $pluginClass && $pluginClass::getServiceType() === 'webhook';
                                            $isManual = $pluginClass && $pluginClass::getServiceType() === 'manual';
                                            $isTask = ($integration['instance_type'] === 'task') || ($integration['service'] === 'task');
                                            $showScheduledUpdates = (!$isWebhook && !$isManual) || $isTask;
                                        @endphp

                                        <div class="flex flex-wrap items-center gap-2 sm:gap-3">
                                            @if ($integration['is_processing'])
                                                <div class="flex items-center space-x-1 sm:space-x-2">
                                                    <x-loading class="loading-spinner loading-xs sm:loading-sm" />
                                                    <x-badge value="{{ __('Processing') }}" class="badge-info badge-xs sm:badge-sm">
                                                        <x-icon name="o-arrow-path" class="w-2 h-2 sm:w-3 sm:h-3 mr-1" />
                                                    </x-badge>
                                                </div>
                                            @elseif ($showScheduledUpdates)
                                                @if ($integration['status'] === 'needs_update')
                                                    <x-badge value="{{ __('Needs Update') }}" class="badge-error badge-xs sm:badge-sm">
                                                        <x-icon name="o-exclamation-triangle" class="w-2 h-2 sm:w-3 sm:h-3 mr-1" />
                                                    </x-badge>
                                                @else
                                                    <x-badge value="{{ __('Up to Date') }}" class="badge-success badge-xs sm:badge-sm">
                                                        <x-icon name="o-check-circle" class="w-2 h-2 sm:w-3 sm:h-3 mr-1" />
                                                    </x-badge>
                                                @endif
                                                @if ($integration['is_paused'])
                                                    <x-badge value="Paused" class="badge-neutral badge-xs sm:badge-sm">
                                                        <x-icon name="o-pause" class="w-2 h-2 sm:w-3 sm:h-3 mr-1" />
                                                    </x-badge>
                                                @endif
                                            @else
                                                @if ($isWebhook)
                                                    <x-badge value="{{ __('Push') }}" class="badge-info badge-xs sm:badge-sm">
                                                        <x-icon name="o-arrow-down-tray" class="w-2 h-2 sm:w-3 sm:h-3 mr-1" />
                                                    </x-badge>
                                                @elseif ($isManual)
                                                    <x-badge value="{{ __('Manual') }}" class="badge-success badge-xs sm:badge-sm">
                                                        <x-icon name="o-hand-raised" class="w-2 h-2 sm:w-3 sm:h-3 mr-1" />
                                                    </x-badge>
                                                @endif
                                            @endif

                                            @if (!is_null($integration['migration_progress']))
                                                @php $phase = $integration['migration_phase']; @endphp
                                                @if ($integration['migration_progress'] >= 100)
                                                    <x-badge value="{{ __('Migrated') }}" class="badge-success badge-sm" />
                                                @else
                                                    <div class="flex items-center space-x-2">
                                                        @if ($phase === 'process')
                                                            <progress class="progress progress-accent progress-xs w-32" value="{{ $integration['migration_progress'] }}" max="100"></progress>
                                                            <span class="text-xs text-base-content/70">{{ $integration['migration_progress'] }}%</span>
                                                        @else
                                                            <progress class="progress progress-info progress-xs w-32" value="0" max="100"></progress>
                                                            <span class="text-xs text-base-content/70">{{ __('Fetching…') }}</span>
                                                        @endif
                                                    </div>
                                                @endif
                                            @endif

                                            @if ($showScheduledUpdates && !$integration['is_processing'] && !$integration['is_paused'] && $integration['status'] === 'needs_update')
                                                <x-button
                                                    label="{{ __('Update') }}"
                                                    icon="o-arrow-path"
                                                    wire:click="triggerUpdate('{{ $integration['id'] }}')"
                                                    :disabled="$integration['is_processing']"
                                                    class="btn btn-xs sm:btn-sm hover:btn-success"
                                                />
                                            @endif
                                            <x-button
                                                label="{{ $integration['is_paused'] ? __('Resume') : __('Pause') }}"
                                                icon="o-pause"
                                                wire:click="togglePause('{{ $integration['id'] }}')"
                                                class="btn btn-xs sm:btn-sm btn-ghost"
                                            />
                                        </div>
                                    </div>




                                    @php
                                        $showUpdateFrequency = !$isWebhook && !$isManual;
                                    @endphp

                                    @if ($showScheduledUpdates)
                                    <div class="grid grid-cols-1 {{ $showUpdateFrequency ? 'sm:grid-cols-2 lg:grid-cols-3' : 'sm:grid-cols-2' }} gap-3 sm:gap-4 text-xs sm:text-sm">
                                        @if ($showUpdateFrequency)
                                        <div class="bg-base-300 rounded-lg p-2 sm:p-3">
                                            <div class="font-medium mb-1">{{ __('Update Frequency') }}</div>
                                            <div class="text-base-content/70">
                                                @php
                                                    $minutes = $integration['update_frequency_minutes'];
                                                    if ($minutes >= 1440) {
                                                        $days = floor($minutes / 1440);
                                                        echo trans_choice('messages.time.day', $days, ['count' => $days]);
                                                    } elseif ($minutes >= 60) {
                                                        $hours = floor($minutes / 60);
                                                        echo trans_choice('messages.time.hour', $hours, ['count' => $hours]);
                                                    } else {
                                                        echo trans_choice('messages.time.minute', $minutes, ['count' => $minutes]);
                                                    }
                                                @endphp
                                            </div>
                                        </div>
                                        @endif

                                        <div class="bg-base-300 rounded-lg p-2 sm:p-3">
                                            <div class="font-medium mb-1">{{ __('Last Update') }}</div>
                                            <div class="text-base-content/70">
                                                @if ($integration['last_successful_update_at'])
                                                    {{ \Carbon\Carbon::parse($integration['last_successful_update_at'])->diffForHumans() }}
                                                @else
                                                    <span class="text-warning">{{ __('Never') }}</span>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="bg-base-300 rounded-lg p-2 sm:p-3">
                                            <div class="font-medium mb-1">{{ __('Next Update') }}</div>
                                            <div class="text-base-content/70">
                                                @if ($integration['next_update_time'])
                                                    {{ \Carbon\Carbon::parse($integration['next_update_time'])->diffForHumans() }}
                                                @else
                                                    <span class="text-warning">{{ __('Unknown') }}</span>
                                                @endif
                                            </div>
                                        </div>
                                        @if ($integration['schedule_summary'])
                                        <div class="bg-base-300 rounded-lg p-2 sm:p-3 col-span-full">
                                            <div class="font-medium mb-1">{{ __('Schedule') }}</div>
                                            <div class="text-base-content/70 text-xs sm:text-sm">
                                                {{ $integration['schedule_summary'] }}
                                            </div>
                                        </div>
                                        @endif
                                        @if (!empty($integration['sweep_enabled']))
                                        <div class="bg-base-300 rounded-lg p-3 md:col-span-3">
                                            <div class="flex items-center justify-between mb-2">
                                                <div class="font-medium">
                                                    {{ $integration['sweep_label'] }}
                                                    <span class="text-xs text-base-content/60 ml-2">{{ $integration['sweep_window'] }}</span>
                                                </div>
                                                <div class="text-xs text-base-content/60">
                                                    @if (!empty($integration['sweep_last_at']))
                                                        {{ __('Last') }}: {{ \Carbon\Carbon::parse($integration['sweep_last_at'])->diffForHumans() }}
                                                    @else
                                                        {{ __('Last') }}: {{ __('never') }}
                                                    @endif
                                                    @if (!empty($integration['sweep_next_at']))
                                                        <span class="ml-3">{{ __('Next') }}: {{ \Carbon\Carbon::parse($integration['sweep_next_at'])->diffForHumans() }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-3">
                                                <progress class="progress progress-info w-full" value="{{ (int) ($integration['sweep_percent'] ?? 0) }}" max="100"></progress>
                                                <span class="text-xs text-base-content/70 w-10 text-right">{{ (int) ($integration['sweep_percent'] ?? 0) }}%</span>
                                            </div>
                                        </div>
                                        @endif
                                    </div>
                                    @else
                                    <!-- Event-driven/Manual integrations section -->
                                    <div class="bg-base-200 rounded-lg">
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm mt-2">
                                            <div class="bg-base-300 rounded-lg p-3">
                                                <div class="font-medium mb-1">
                                                    @if ($isWebhook)
                                                        {{ __('Last Data Received') }}
                                                    @elseif ($isManual)
                                                        {{ __('Last Entry') }}
                                                    @endif
                                                </div>
                                                <div class="text-base-content/70">
                                                    @if ($integration['last_successful_update_at'])
                                                        {{ \Carbon\Carbon::parse($integration['last_successful_update_at'])->diffForHumans() }}
                                                    @else
                                                        <span class="text-warning">
                                                            @if ($isWebhook)
                                                                {{ __('No data received yet') }}
                                                            @elseif ($isManual)
                                                                {{ __('No entries yet') }}
                                                            @endif
                                                        </span>
                                                    @endif
                                                </div>
                                            </div>

                                            <div class="bg-base-300 rounded-lg p-3">
                                                <div class="font-medium mb-1">{{ __('Status') }}</div>
                                                <div class="text-base-content/70">
                                                    @if ($isWebhook)
                                                        <span class="text-info">{{ __('Waiting for webhook events') }}</span>
                                                    @elseif ($isManual)
                                                        <span class="text-success">{{ __('Ready for manual entries') }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    @endif

                                    @if ($integration['last_triggered_at'] && $integration['last_triggered_at'] !== $integration['last_successful_update_at'])
                                        <div class="mt-3 p-3 bg-warning/10 border border-warning/20 rounded-lg">
                                            <div class="flex items-center space-x-2">
                                                <x-icon name="fas.exclamation-triangle" class="w-4 h-4 text-warning" />
                                                <span class="text-sm text-warning">
                                                    {{ __('Last triggered') }}: {{ \Carbon\Carbon::parse($integration['last_triggered_at'])->diffForHumans() }}
                                                    @if (!$integration['last_successful_update_at'] || $integration['last_triggered_at'] > $integration['last_successful_update_at'])
                                                        {{ __('(may be processing)') }}
                                                    @endif
                                                </span>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @endforeach
                    </div>

                    <div class="mt-6 p-4 bg-base-300 rounded-lg">
                        <div class="flex items-center space-x-2 mb-2">
                            <x-icon name="fas.info-circle" class="w-4 h-4 text-info" />
                            <span class="font-medium">{{ __('About Updates') }}</span>
                        </div>
                        <p class="text-sm text-base-content/70">
                            {{ __('Updates are automatically scheduled based on your integration frequency settings. You can manually trigger updates at any time. The system will retry failed updates up to 3 times with increasing delays.') }}
                        </p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Toast notifications -->
    <x-toast position="toast-top toast-end" />
</div>
