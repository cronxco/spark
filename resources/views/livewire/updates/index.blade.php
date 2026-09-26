<?php
use App\Actions\DispatchIntegrationFetchJobs;
use App\Integrations\Contracts\SupportsSweeps;
use App\Integrations\PluginRegistry;
use App\Models\ActionProgress;
use App\Models\Event;
use App\Models\Integration;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component
{
    use Toast;

    public string $filter = 'all';

    public string $search = '';

    /** @var array<string, bool> Expanded state keyed by service slug. */
    public array $expanded = [];

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'integrations', 'tasks'], true) ? $filter : 'all';
    }

    public function resetFilters(): void
    {
        $this->filter = 'all';
        $this->search = '';
    }

    public function toggle(string $service): void
    {
        $this->expanded[$service] = ! ($this->expanded[$service] ?? false);
    }

    /**
     * @return array{total: int, needs_attention: int, processing: int, paused: int, stale: int}
     */
    #[Computed]
    public function summary(): array
    {
        $statuses = collect($this->groups)->flatMap(fn (array $group) => array_column($group['instances'], 'status'));
        $failed = collect($this->groups)->sum(fn (array $group) => $group['counts']['failed']);

        return [
            'total' => $statuses->count(),
            'needs_attention' => $statuses->filter(fn (string $status) => $status === 'needs_update')->count() + $failed,
            'processing' => $statuses->filter(fn (string $status) => $status === 'processing')->count(),
            'paused' => $statuses->filter(fn (string $status) => $status === 'paused')->count(),
            'stale' => $statuses->filter(fn (string $status) => $status === 'stale')->count(),
        ];
    }

    #[Computed]
    public function hasAnyIntegrations(): bool
    {
        return Auth::user()->integrations()->exists();
    }

    /**
     * Integrations grouped by plugin, groups needing attention first.
     *
     * @return array<string, array<string, mixed>>
     */
    #[Computed]
    public function groups(): array
    {
        /** @var User $user */
        $user = Auth::user();

        $integrations = $user->integrations()
            ->addSelect(['last_event_time' => Event::select('time')
                ->whereColumn('integration_id', 'integrations.id')
                ->orderByDesc('time')
                ->limit(1),
            ])
            ->orderBy('integration_group_id')
            ->orderBy('name')
            ->get()
            ->filter(fn (Integration $integration) => $this->matchesFilters($integration));

        $migrationProgress = ActionProgress::query()
            ->where('user_id', $user->id)
            ->where('action_type', 'migration')
            ->whereIn('action_id', $integrations->map(fn (Integration $integration) => "integration_{$integration->id}"))
            ->latest()
            ->get()
            ->unique('action_id')
            ->keyBy('action_id');

        $groups = $integrations
            ->groupBy('service')
            ->map(fn (Collection $instances, string $service) => $this->describeGroup($service, $instances, $migrationProgress))
            ->all();

        foreach ($groups as $service => $group) {
            $this->expanded[$service] ??= $group['attention'] > 0;
        }

        uasort($groups, fn (array $a, array $b) => [$b['attention'] > 0, $b['attention'], $a['name']] <=> [$a['attention'] > 0, $a['attention'], $b['name']]);

        return $groups;
    }

    private function matchesFilters(Integration $integration): bool
    {
        if ($this->filter === 'tasks' && ! $integration->isTaskInstance()) {
            return false;
        }

        if ($this->filter === 'integrations' && $integration->isTaskInstance()) {
            return false;
        }

        $needle = Str::lower(trim($this->search));

        if ($needle === '') {
            return true;
        }

        $pluginClass = PluginRegistry::getPlugin($integration->service);
        $haystack = Str::lower(implode(' ', [
            $integration->name,
            str_replace('_', ' ', $integration->service),
            $pluginClass ? $pluginClass::getDisplayName() : '',
        ]));

        return str_contains($haystack, $needle);
    }

    /**
     * @param  Collection<int, Integration>  $instances
     * @param  Collection<string, ActionProgress>  $migrationProgress
     * @return array<string, mixed>
     */
    private function describeGroup(string $service, Collection $instances, Collection $migrationProgress): array
    {
        $pluginClass = PluginRegistry::getPlugin($service);
        $serviceType = $pluginClass ? $pluginClass::getServiceType() : 'oauth';
        $receivesPushedData = in_array($serviceType, ['webhook', 'manual'], true);
        $name = $pluginClass ? $pluginClass::getDisplayName() : Str::headline($service);

        $rows = $instances->map(fn (Integration $integration) => $this->describeInstance(
            $integration,
            $receivesPushedData && ! $integration->isTaskInstance(),
            $serviceType,
            $name,
            $migrationProgress->get("integration_{$integration->id}"),
        ))->values()->all();

        $cadences = collect($rows)->pluck('cadence')->filter()->unique();
        $sharedCadence = $cadences->count() === 1 ? $cadences->first() : null;

        $statuses = collect($rows)->pluck('status');
        $counts = [
            'needs_update' => $statuses->filter(fn (string $status) => $status === 'needs_update')->count(),
            'processing' => $statuses->filter(fn (string $status) => $status === 'processing')->count(),
            'paused' => $statuses->filter(fn (string $status) => $status === 'paused')->count(),
            'stale' => $statuses->filter(fn (string $status) => $status === 'stale')->count(),
            'failed' => collect($rows)->filter(fn (array $row) => $row['migration']['failed'] ?? false)->count(),
        ];

        return [
            'service' => $service,
            'name' => $name,
            'icon' => $pluginClass ? $pluginClass::getIcon() : 'fas.puzzle-piece',
            'service_type' => $serviceType,
            'cadence' => $sharedCadence,
            'sweep' => $this->describeSweep($pluginClass, $instances),
            'counts' => $counts,
            'attention' => $counts['needs_update'] + $counts['failed'],
            'instances' => array_map(fn (array $row) => [...$row, 'show_cadence' => $sharedCadence === null], $rows),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeInstance(Integration $integration, bool $receivesPushedData, string $serviceType, string $pluginName, ?ActionProgress $progress): array
    {
        $lastEventTime = $integration->last_event_time ? Carbon::parse($integration->last_event_time) : null;

        return [
            'id' => $integration->id,
            'name' => $integration->name ?: $pluginName,
            'status' => $integration->statusKey($lastEventTime),
            'receives_pushed_data' => $receivesPushedData,
            'is_manual' => $serviceType === 'manual',
            'can_trigger' => ! $receivesPushedData,
            'last_at' => $receivesPushedData ? $lastEventTime : $integration->last_successful_update_at,
            'next_at' => $receivesPushedData ? null : $integration->getNextUpdateTime(),
            'cadence' => $receivesPushedData ? null : $this->describeCadence($integration),
            'migration' => $this->describeMigration($integration, $progress),
        ];
    }

    private function describeCadence(Integration $integration): string
    {
        if ($summary = $integration->getScheduleSummary()) {
            return $summary;
        }

        $minutes = $integration->getUpdateFrequencyMinutes();

        return match (true) {
            $minutes === 60 => __('Hourly'),
            $minutes === 1440 => __('Daily'),
            $minutes % 1440 === 0 => __('Every :count days', ['count' => $minutes / 1440]),
            $minutes % 60 === 0 => __('Every :count hours', ['count' => $minutes / 60]),
            default => trans_choice('{1} Every minute|[2,*] Every :count minutes', $minutes, ['count' => $minutes]),
        };
    }

    /**
     * The most recent sweep across a plugin's instances; sweeps are a plugin-level concern.
     *
     * @param  Collection<int, Integration>  $instances
     * @return array{label: string, window: string, last_at: ?Carbon, next_at: ?Carbon}|null
     */
    private function describeSweep(?string $pluginClass, Collection $instances): ?array
    {
        if (! $pluginClass || ! is_a($pluginClass, SupportsSweeps::class, true)) {
            return null;
        }

        $schedule = $pluginClass::getSweepSchedule();
        $lastAt = $instances
            ->map(fn (Integration $integration) => $integration->configuration[$schedule['config_key']] ?? null)
            ->filter()
            ->map(fn (string $time) => Carbon::parse($time))
            ->sortDesc()
            ->first();

        return [
            'label' => $schedule['label'],
            'window' => $schedule['window'],
            'last_at' => $lastAt,
            'next_at' => $lastAt?->copy()->addHours($schedule['period_hours']),
        ];
    }

    /**
     * Only surfaces a migration while it is running or after it failed.
     *
     * @return array{percent: ?int, message: ?string, failed: bool}|null
     */
    private function describeMigration(Integration $integration, ?ActionProgress $progress): ?array
    {
        if ($progress?->isFailed()) {
            return ['percent' => null, 'message' => $progress->message, 'failed' => true];
        }

        if ($progress && $progress->progress < 100) {
            return ['percent' => (int) $progress->progress, 'message' => $progress->message, 'failed' => false];
        }

        if (! $integration->migration_batch_id) {
            return null;
        }

        try {
            $batch = Bus::findBatch($integration->migration_batch_id);
        } catch (Throwable) {
            return null;
        }

        if (! $batch || $batch->progress() >= 100) {
            return null;
        }

        $fetchedBackTo = $integration->service === 'monzo'
            ? (Cache::get("monzo:migration:{$integration->id}:fetched_back_to") ?: Cache::get("monzo:migration:{$integration->id}:balances_last_date"))
            : null;

        return [
            'percent' => $batch->progress(),
            'message' => $fetchedBackTo ? __('Fetched back to :date', ['date' => $fetchedBackTo]) : null,
            'failed' => false,
        ];
    }

    public function triggerUpdate(string $integrationId): void
    {
        $integration = $this->ownedIntegration($integrationId);

        if (! $integration) {
            return;
        }

        try {
            (new DispatchIntegrationFetchJobs)->dispatch($integration);
            $this->success(__('Update started for :name.', ['name' => $integration->name]));
        } catch (Throwable) {
            $this->error(__("Couldn't start the update. Try again in a moment."));
        }

        unset($this->groups, $this->summary);
    }

    public function togglePause(string $integrationId): void
    {
        $integration = $this->ownedIntegration($integrationId);

        if (! $integration) {
            return;
        }

        $config = $integration->configuration ?? [];
        $config['paused'] = ! ((bool) ($config['paused'] ?? false));
        $integration->update(['configuration' => $config]);

        $this->success($config['paused']
            ? __(':name is paused.', ['name' => $integration->name])
            : __(':name will update again.', ['name' => $integration->name]));

        unset($this->groups, $this->summary);
    }

    private function ownedIntegration(string $integrationId): ?Integration
    {
        $integration = Auth::user()->integrations()->find($integrationId);

        if (! $integration) {
            $this->error(__('That integration no longer exists.'));
        }

        return $integration;
    }
}; ?>

<div wire:poll.5s class="flex flex-col gap-8">
    <x-header title="{{ __('Updates') }}" subtitle="{{ __('When each integration last synced, and what runs next') }}" separator>
        <x-slot:actions>
            <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-center">
                <div class="join" role="group" aria-label="{{ __('Show') }}">
                    @foreach (['all' => __('All'), 'integrations' => __('Integrations'), 'tasks' => __('Tasks')] as $value => $label)
                        <button
                            type="button"
                            wire:click="setFilter('{{ $value }}')"
                            aria-pressed="{{ $filter === $value ? 'true' : 'false' }}"
                            class="btn btn-sm join-item {{ $filter === $value ? 'btn-primary' : 'btn-outline' }}"
                        >{{ $label }}</button>
                    @endforeach
                </div>
                <div class="min-w-0 sm:w-64">
                    <x-input
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search integrations') }}"
                        icon="fas.magnifying-glass"
                        clearable
                    />
                </div>
            </div>
        </x-slot:actions>
    </x-header>

    @if (! $this->hasAnyIntegrations)
        <div class="mx-auto flex max-w-[42ch] flex-col items-center gap-3 py-12 text-center">
            <x-icon name="fas.cloud-arrow-down" class="h-10 w-10 text-base-content/40" />
            <h2 class="text-lg font-semibold">{{ __('No integrations yet') }}</h2>
            <p class="text-base-content/70">{{ __('Connect an integration and its sync status will show up here.') }}</p>
            <x-button :label="__('Connect an integration')" :link="route('integrations.index')" class="btn-primary rounded-full" />
        </div>
    @elseif (count($this->groups) === 0)
        <div class="mx-auto flex max-w-[42ch] flex-col items-center gap-3 py-12 text-center">
            <x-icon name="fas.magnifying-glass" class="h-10 w-10 text-base-content/40" />
            <h2 class="text-lg font-semibold">{{ __('Nothing matches') }}</h2>
            <p class="text-base-content/70">{{ __('No integrations match this filter. Clear it to see everything again.') }}</p>
            <x-button :label="__('Clear filters')" wire:click="resetFilters" class="btn-primary rounded-full" />
        </div>
    @else
        @php($summary = $this->summary)
        <div role="status" class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span @class([
                'size-2.5 rounded-full',
                'bg-error' => $summary['needs_attention'] > 0,
                'bg-info' => $summary['needs_attention'] === 0 && $summary['processing'] > 0,
                'bg-success' => $summary['needs_attention'] === 0 && $summary['processing'] === 0,
            ]) aria-hidden="true"></span>
            <span class="font-semibold">
                @if ($summary['needs_attention'] > 0)
                    {{ trans_choice('{1} 1 integration needs attention|[2,*] :count integrations need attention', $summary['needs_attention'], ['count' => $summary['needs_attention']]) }}
                @elseif ($summary['processing'] > 0)
                    {{ trans_choice('{1} Updating 1 integration|[2,*] Updating :count integrations', $summary['processing'], ['count' => $summary['processing']]) }}
                @else
                    {{ __('Everything is up to date') }}
                @endif
            </span>
            @php($trailing = array_filter([
                $summary['paused'] ? trans_choice('{1} 1 paused|[2,*] :count paused', $summary['paused'], ['count' => $summary['paused']]) : null,
                $summary['stale'] ? trans_choice('{1} 1 quiet|[2,*] :count quiet', $summary['stale'], ['count' => $summary['stale']]) : null,
            ]))
            @if ($trailing)
                <span class="text-base-content/70">{{ implode(' · ', $trailing) }}</span>
            @endif
        </div>

        <div class="flex flex-col gap-6">
            @foreach ($this->groups as $service => $group)
                @php($isOpen = $this->expanded[$service] ?? false)
                <section wire:key="group-{{ $service }}" class="rounded-box border border-base-300 bg-base-100">
                    <h2>
                        <button
                            type="button"
                            wire:click="toggle('{{ $service }}')"
                            aria-expanded="{{ $isOpen ? 'true' : 'false' }}"
                            aria-controls="group-{{ $service }}"
                            class="flex w-full items-center gap-3 rounded-box p-4 text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                        >
                            <x-icon :name="$group['icon']" class="h-5 w-5 shrink-0" />
                            <span class="flex min-w-0 flex-1 flex-col">
                                <span class="font-semibold">{{ $group['name'] }}</span>
                                <span class="text-sm text-base-content/70">
                                    {{ trans_choice('{1} 1 instance|[2,*] :count instances', count($group['instances']), ['count' => count($group['instances'])]) }}
                                    @if ($group['service_type'] === 'webhook')
                                        · {{ __('Receives pushed data') }}
                                    @elseif ($group['service_type'] === 'manual')
                                        · {{ __('Manual entries') }}
                                    @elseif ($group['cadence'])
                                        · {{ $group['cadence'] }}
                                    @endif
                                    @if ($group['sweep'])
                                        · {{ $group['sweep']['label'] }} {{ __('of the') }} {{ $group['sweep']['window'] }},
                                        @if ($group['sweep']['last_at'])
                                            <x-relative-time :time="$group['sweep']['last_at']" />
                                        @else
                                            {{ __('not run yet') }}
                                        @endif
                                    @endif
                                </span>
                            </span>
                            <span class="flex shrink-0 items-center gap-2">
                                @if ($group['counts']['needs_update'] > 0)
                                    <x-badge :value="trans_choice('{1} 1 needs update|[2,*] :count need update', $group['counts']['needs_update'], ['count' => $group['counts']['needs_update']])" class="badge-error badge-sm" />
                                @endif
                                @if ($group['counts']['failed'] > 0)
                                    <x-badge :value="trans_choice('{1} 1 migration failed|[2,*] :count migrations failed', $group['counts']['failed'], ['count' => $group['counts']['failed']])" class="badge-error badge-sm" />
                                @endif
                                @if ($group['counts']['processing'] > 0)
                                    <span class="flex items-center gap-1 text-sm text-base-content/70">
                                        <x-loading class="loading-spinner loading-xs" />
                                        {{ __('Updating') }}
                                    </span>
                                @endif
                                @if ($group['counts']['paused'] > 0)
                                    <x-badge :value="trans_choice('{1} 1 paused|[2,*] :count paused', $group['counts']['paused'], ['count' => $group['counts']['paused']])" class="badge-neutral badge-sm" />
                                @endif
                                @if ($group['counts']['stale'] > 0)
                                    <span class="text-sm text-base-content/70">{{ trans_choice('{1} 1 quiet|[2,*] :count quiet', $group['counts']['stale'], ['count' => $group['counts']['stale']]) }}</span>
                                @endif
                                <x-icon :name="$isOpen ? 'fas.chevron-up' : 'fas.chevron-down'" class="h-4 w-4 text-base-content/70" />
                            </span>
                        </button>
                    </h2>

                    @if ($isOpen)
                        <ul id="group-{{ $service }}" class="divide-y divide-base-300 border-t border-base-300">
                            @foreach ($group['instances'] as $instance)
                                <li wire:key="instance-{{ $instance['id'] }}" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
                                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <a href="{{ route('integrations.details', $instance['id']) }}" class="truncate font-semibold hover:underline">{{ $instance['name'] }}</a>
                                            @if ($instance['status'] === 'needs_update')
                                                <x-badge :value="__('Needs update')" class="badge-error badge-sm" />
                                            @elseif ($instance['status'] === 'processing')
                                                <span class="flex items-center gap-1 text-sm text-base-content/70">
                                                    <x-loading class="loading-spinner loading-xs" />
                                                    {{ __('Updating') }}
                                                </span>
                                            @elseif ($instance['status'] === 'paused')
                                                <x-badge :value="__('Paused')" class="badge-neutral badge-sm" />
                                            @endif
                                            @if ($instance['migration']['failed'] ?? false)
                                                <x-badge :value="__('Migration failed')" class="badge-error badge-sm" />
                                            @endif
                                        </div>

                                        <p class="text-sm text-base-content/70">
                                            @if ($instance['receives_pushed_data'])
                                                @if ($instance['last_at'])
                                                    {{ $instance['is_manual'] ? __('Last entry') : __('Last data') }}
                                                    <x-relative-time :time="$instance['last_at']" />
                                                    @if ($instance['status'] === 'stale')
                                                        · {{ __('Quiet for a while — nothing to fix unless you expected more.') }}
                                                    @endif
                                                @else
                                                    {{ $instance['is_manual'] ? __('No entries yet') : __('No data received yet') }}
                                                @endif
                                            @else
                                                @if ($instance['last_at'])
                                                    {{ __('Updated') }} <x-relative-time :time="$instance['last_at']" />
                                                @else
                                                    {{ __('Never updated') }}
                                                @endif
                                                @if ($instance['next_at'] && $instance['status'] !== 'paused')
                                                    · {{ $instance['next_at']->isPast() ? __('Was due') : __('Next') }} <x-relative-time :time="$instance['next_at']" />
                                                @endif
                                                @if ($instance['show_cadence'] && $instance['cadence'])
                                                    · {{ $instance['cadence'] }}
                                                @endif
                                            @endif
                                        </p>

                                        @if ($instance['migration'] && ! $instance['migration']['failed'])
                                            <div class="flex items-center gap-3">
                                                <progress class="progress w-40" value="{{ $instance['migration']['percent'] ?? 0 }}" max="100" aria-label="{{ __('Migration progress') }}"></progress>
                                                <span class="text-sm text-base-content/70">{{ $instance['migration']['percent'] ?? 0 }}%</span>
                                                @if ($instance['migration']['message'])
                                                    <span class="truncate text-sm text-base-content/70">{{ $instance['migration']['message'] }}</span>
                                                @endif
                                            </div>
                                        @endif
                                    </div>

                                    <div class="flex shrink-0 items-center gap-2">
                                        @if ($instance['can_trigger'] && ! in_array($instance['status'], ['processing', 'paused'], true))
                                            <x-button
                                                icon="fas.rotate"
                                                :label="__('Update now')"
                                                wire:click="triggerUpdate('{{ $instance['id'] }}')"
                                                spinner="triggerUpdate('{{ $instance['id'] }}')"
                                                class="btn-ghost btn-sm"
                                            />
                                        @endif
                                        <x-button
                                            :icon="$instance['status'] === 'paused' ? 'fas.play' : 'fas.pause'"
                                            :label="$instance['status'] === 'paused' ? __('Resume') : __('Pause')"
                                            wire:click="togglePause('{{ $instance['id'] }}')"
                                            class="btn-ghost btn-sm"
                                        />
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endforeach
        </div>
    @endif

    <x-toast position="toast-top toast-end" />
</div>
