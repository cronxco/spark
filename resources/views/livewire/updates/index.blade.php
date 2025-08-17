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
                'update_frequency_minutes' => $integration->update_frequency_minutes,
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

<div class="py-12" wire:poll.5s="refreshData">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <x-card title="{{ __('Integration Updates') }}" shadow>
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center space-x-4">
                    <div class="flex items-center space-x-2">
                        <x-icon name="fas.cloud-arrow-down" class="w-5 h-5 text-primary" />
                        <span class="text-lg font-semibold">{{ __('Updates') }}</span>
                    </div>

                    @if ($isRefreshing)
                        <div class="flex items-center space-x-2">
                            <x-loading class="loading-spinner loading-sm" />
                            <span class="text-sm text-base-content/70">{{ __('Refreshing...') }}</span>
                        </div>
                    @endif
                </div>

                <x-button
                    label="{{ __('Refresh') }}"
                    icon="fas.sync-alt"
                    wire:click="refreshData"
                    :disabled="$isRefreshing"
                    class="btn-outline"
                />
            </div>

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
                        <div class="border border-base-300 rounded-lg p-4 bg-base-200">
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 rounded-lg bg-base-300 flex items-center justify-center">
                                        @if ($integration['service'] === 'github')
                                            <svg class="w-5 h-5 text-base-content" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/>
                                            </svg>
                                        @elseif ($integration['service'] === 'slack')
                                            <svg class="w-5 h-5 text-base-content" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M6.194 14.644c0 1.16-.943 2.107-2.107 2.107-1.164 0-2.107-.947-2.107-2.107 0-1.16.943-2.106 2.107-2.106 1.164 0 2.107.946 2.107 2.106zm5.882-2.107c-1.164 0-2.107.946-2.107 2.106 0 1.16.943 2.107 2.107 2.107 1.164 0 2.107-.947 2.107-2.107 0-1.16-.943-2.106-2.107-2.106zm2.107-5.882c0-1.164-.943-2.107-2.107-2.107-1.164 0-2.107.943-2.107 2.107 0 1.164.943 2.107 2.107 2.107 1.164 0 2.107-.943 2.107-2.107zm2.106 5.882c0-1.164-.943-2.107-2.107-2.107-1.164 0-2.107.943-2.107 2.107 0 1.164.943 2.107 2.107 2.107 1.164 0 2.107-.943 2.107-2.107zm5.882-2.107c-1.164 0-2.107.946-2.107 2.106 0 1.16.943 2.107 2.107 2.107 1.164 0 2.107-.947 2.107-2.107 0-1.16-.943-2.106-2.107-2.106z"/>
                                            </svg>
                                        @elseif ($integration['service'] === 'spotify')
                                            <svg class="w-5 h-5 text-base-content" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/>
                                            </svg>
                                        @else
                                            <x-icon name="fas.puzzle-piece" class="w-5 h-5 text-base-content" />
                                        @endif
                                    </div>
                                    <div>
                                        <h4 class="text-lg font-semibold">{{ $integration['name'] }}</h4>
                                        <p class="text-sm text-base-content/70">{{ ucfirst($integration['service']) }}</p>
                                    </div>
                                </div>

                                <div class="flex items-center space-x-3">
                                    @if ($integration['is_processing'])
                                        <div class="flex items-center space-x-2">
                                            <x-loading class="loading-spinner loading-sm" />
                                            <x-badge value="{{ __('Processing') }}" class="badge-info badge-sm" />
                                        </div>
                                    @else
                                        <x-badge
                                            :value="$integration['status'] === 'needs_update' ? __('Needs Update') : __('Up to Date')"
                                            :class="$integration['status'] === 'needs_update' ? 'badge-warning' : 'badge-success'"
                                            class="badge-sm"
                                        />
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

                                    @if (!$integration['is_processing'])
                                        <x-button
                                            label="{{ __('Update Now') }}"
                                            icon="fas.sync-alt"
                                            wire:click="triggerUpdate('{{ $integration['id'] }}')"
                                            :disabled="$integration['is_processing']"
                                            class="btn-primary btn-sm"
                                        />
                                    @endif
                                </div>
                            </div>

                            @if ($integration['service'] === 'monzo'
                                && !is_null($integration['migration_progress'])
                                && $integration['migration_progress'] < 100
                                && $integration['migration_fetched_back_to'])
                                <div class="mt-1 text-xs text-base-content/60 flex flex-wrap items-center gap-x-2">
                                    <span>{{ __('Fetched to') }}: {{ $integration['migration_fetched_back_to'] }}</span>
                                </div>
                            @endif

                            @if ($integration['account_id'])
                                <div class="text-sm text-base-content/70 mb-3">
                                    <x-icon name="fas.user" class="w-3 h-3 inline mr-1" />
                                    {{ __('Account') }}: {{ $integration['account_id'] }}
                                </div>
                            @endif

                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                                <div class="bg-base-300 rounded-lg p-3">
                                    <div class="font-medium mb-1">{{ __('Update Frequency') }}</div>
                                    <div class="text-base-content/70">{{ $integration['update_frequency_minutes'] }} {{ __('minutes') }}</div>
                                </div>

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
        </x-card>
    </div>
</div>
