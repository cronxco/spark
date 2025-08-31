<?php
use App\Jobs\ProcessIntegrationData;
use App\Models\Integration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public array $integrations = [];
    public bool $isRefreshing = false;

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $userIntegrations = $user->integrations()
            ->with('user')
            ->orderBy('last_successful_update_at', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();

        $this->integrations = $userIntegrations->map(function ($integration) {
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

            return [
                'id' => $integration->id,
                'name' => $integration->name ?: $integration->service,
                'service' => $integration->service,
                'account_id' => $integration->account_id,
                'update_frequency_minutes' => $integration->getUpdateFrequencyMinutes(),
                'last_triggered_at' => $integration->last_triggered_at ? $integration->last_triggered_at->toISOString() : null,
                'last_successful_update_at' => $integration->last_successful_update_at ? $integration->last_successful_update_at->toISOString() : null,
                'needs_update' => $integration->needsUpdate(),
                'next_update_time' => $integration->getNextUpdateTime() ? $integration->getNextUpdateTime()->toISOString() : null,
                'is_processing' => $integration->isProcessing(),
                'status' => $this->getIntegrationStatus($integration),
                'migration_progress' => $batchProgress,
                'migration_batch_id' => $integration->migration_batch_id,
                'migration_batch_name' => $batchName,
                'migration_phase' => $migrationPhase,
                'migration_fetched_back_to' => $fetchedBackTo,
                'migration_tx_window_count' => $txWindowCount,
            ];
        })->toArray();
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
            // Dispatch the job
            ProcessIntegrationData::dispatch($integration);

            $this->success('Update triggered successfully!');
            $this->loadData();

        } catch (\Exception $e) {
            $this->error('Failed to trigger update. Please try again.');
        }
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
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-base-content">Updates</h1>
                <p class="text-base-content/70">Monitor and manage your integration data updates</p>
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
                    <div class="space-y-4">
                        @foreach ($integrations as $integration)
                            <div class="card bg-base-200 shadow-sm">
                                <div class="card-body">
                                    <div class="flex items-start justify-between mb-4">
                                        <div class="flex items-center space-x-3">
                                            <div class="w-10 h-10 rounded-lg bg-base-300 flex items-center justify-center">
                                                @php
                                                    $pluginClass = \App\Integrations\PluginRegistry::getPlugin($integration['service']);
                                                    $icon = $pluginClass ? $pluginClass::getIcon() : 'fas.puzzle-piece';
                                                @endphp
                                                <x-icon :name="$icon" class="w-5 h-5 text-base-content" />
                                            </div>
                                            <div>
                                                <h4 class="text-lg font-semibold">
                                                    <a href="{{ route('integrations.details', $integration['id']) }}" class="hover:text-primary transition-colors">
                                                        {{ $integration['name'] }}
                                                    </a>
                                                </h4>
                                                <span class="text-sm text-base-content/70">
                                                    {{ \Illuminate\Support\Str::of($integration['service'])->replace('_', ' ')->title() }}
                                                </span>
                                                @if ($integration['service'] === 'monzo'
                                                && !is_null($integration['migration_progress'])
                                                && $integration['migration_progress'] < 100
                                                && $integration['migration_fetched_back_to'])
                                                    <span class="text-xs text-base-content/70">
                                                        {{ __('Fetched to') }}: {{ $integration['migration_fetched_back_to'] }}
                                                    </span>
                                                @endif
                                                @if ($integration['account_id'])
                                                    <span class="text-xs text-base-content/70 mb-3">
                                                        {{ __('Account') }}: {{ $integration['account_id'] }}
                                                    </span>
                                                @endif
                                            </div>
                                        </div>

                                        @php
                                            $pluginClass = \App\Integrations\PluginRegistry::getPlugin($integration['service']);
                                            $isWebhook = $pluginClass && $pluginClass::getServiceType() === 'webhook';
                                            $isManual = $pluginClass && $pluginClass::getServiceType() === 'manual';
                                            $showScheduledUpdates = !$isWebhook && !$isManual;
                                        @endphp

                                        <div class="flex items-center space-x-3">
                                            @if ($integration['is_processing'])
                                                <div class="flex items-center space-x-2">
                                                    <x-loading class="loading-spinner loading-sm" />
                                                    <x-badge value="{{ __('Processing') }}" class="badge-outline badge-info badge-sm" />
                                                </div>
                                            @elseif ($showScheduledUpdates)
                                                <x-badge
                                                    :value="$integration['status'] === 'needs_update' ? __('Needs Update') : __('Up to Date')"
                                                    :class="$integration['status'] === 'needs_update' ? 'badge-outline badge-warning' : 'badge-outline badge-success'"
                                                    class="badge-sm"
                                                />
                                            @else
                                                @if ($isWebhook)
                                                    <x-badge value="{{ __('Push') }}" class="badge-outline badge-info badge-sm" />
                                                @elseif ($isManual)
                                                    <x-badge value="{{ __('Manual') }}" class="badge-outline badge-success badge-sm" />
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

                                            @if ($showScheduledUpdates && !$integration['is_processing'] && $integration['status'] === 'needs_update')
                                                <x-button
                                                    label="{{ __('Update') }}"
                                                    icon="o-arrow-path"
                                                    wire:click="triggerUpdate('{{ $integration['id'] }}')"
                                                    :disabled="$integration['is_processing']"
                                                    class="btn btn-xs hover:btn-success"
                                                />
                                            @endif
                                        </div>
                                    </div>


                                    

                                    @php
                                        $showUpdateFrequency = !$isWebhook && !$isManual;
                                    @endphp

                                    @if ($showScheduledUpdates)
                                    <div class="grid grid-cols-1 {{ $showUpdateFrequency ? 'md:grid-cols-3' : 'md:grid-cols-2' }} gap-4 text-sm">
                                        @if ($showUpdateFrequency)
                                        <div class="bg-base-300 rounded-lg p-3">
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

                                        <div class="bg-base-300 rounded-lg p-3">
                                            <div class="font-medium mb-1">{{ __('Last Update') }}</div>
                                            <div class="text-base-content/70">
                                                @if ($integration['last_successful_update_at'])
                                                    {{ \Carbon\Carbon::parse($integration['last_successful_update_at'])->diffForHumans() }}
                                                @else
                                                    <span class="text-warning">{{ __('Never') }}</span>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="bg-base-300 rounded-lg p-3">
                                            <div class="font-medium mb-1">{{ __('Next Update') }}</div>
                                            <div class="text-base-content/70">
                                                @if ($integration['next_update_time'])
                                                    {{ \Carbon\Carbon::parse($integration['next_update_time'])->diffForHumans() }}
                                                @else
                                                    <span class="text-warning">{{ __('Unknown') }}</span>
                                                @endif
                                            </div>
                                        </div>
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
