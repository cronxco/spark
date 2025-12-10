@props(['integration', 'showStatus' => true])

@php
use App\Integrations\PluginRegistry;

// Handle null integrations gracefully
if (!$integration) {
    return;
}

// Get plugin info
$pluginClass = PluginRegistry::getPlugin($integration->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas.plug';
$serviceName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($integration->service);
$accentColor = $pluginClass ? $pluginClass::getAccentColor() : 'primary';
$domain = $pluginClass ? $pluginClass::getDomain() : 'knowledge';
$serviceType = $pluginClass ? $pluginClass::getServiceType() : 'manual';

// Get instance type info
$instanceTypes = $pluginClass ? $pluginClass::getInstanceTypes() : [];
$instanceTypeInfo = $instanceTypes[$integration->instance_type] ?? null;
$instanceDisplayName = $instanceTypeInfo['display_name'] ?? Str::headline($integration->instance_type ?? 'Instance');

// Determine status
$isPaused = $integration->isPaused();
$isStale = $integration->isStale();
$isProcessing = $integration->isProcessing();

$statusColor = 'success';
$statusText = 'Active';
$statusIcon = 'fas.check-circle';

if ($isPaused) {
    $statusColor = 'warning';
    $statusText = 'Paused';
    $statusIcon = 'fas.pause-circle';
} elseif ($isStale) {
    $statusColor = 'error';
    $statusText = 'Stale';
    $statusIcon = 'fas.exclamation-circle';
} elseif ($isProcessing) {
    $statusColor = 'info';
    $statusText = 'Processing';
    $statusIcon = 'fas.spinner';
}

// Get timing info
$lastUpdate = $integration->last_successful_update_at;
$nextUpdate = $integration->getNextUpdateTime();

// Generate unique ID for this popover instance (not just per integration, but per render)
$popoverId = 'integration-ref-' . $integration->id . '-' . Str::random(8);
@endphp

<span
    x-data="{
        open: false,
        showTimeout: null,
        hideTimeout: null,
        popoverId: '{{ $popoverId }}',
        isMobile: window.innerWidth < 768,
        show() {
            if (this.isMobile) return;
            clearTimeout(this.hideTimeout);
            this.showTimeout = setTimeout(() => {
                window.dispatchEvent(new CustomEvent('popover-opening', { detail: this.popoverId }));
                this.open = true;
            }, 200);
        },
        hide() {
            clearTimeout(this.showTimeout);
            this.hideTimeout = setTimeout(() => { this.open = false; }, 150);
        },
        keepOpen() {
            clearTimeout(this.hideTimeout);
        },
        toggle() {
            if (this.isMobile) {
                window.dispatchEvent(new CustomEvent('popover-opening', { detail: this.popoverId }));
                this.open = !this.open;
            }
        },
        closeIfNotMe(event) {
            if (event.detail !== this.popoverId) {
                clearTimeout(this.showTimeout);
                this.open = false;
            }
        }
    }"
    @mouseenter="show()"
    @mouseleave="hide()"
    @popover-opening.window="closeIfNotMe($event)"
    @click="toggle()"
    @keydown.escape="open = false"
    class="relative inline-block"
>
    {{-- Trigger: The reference link/badge --}}
    <a
        href="{{ route('integrations.configure', $integration) }}"
        wire:navigate
        @click.stop="if (isMobile) { $event.preventDefault(); toggle(); }"
        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-sm font-medium
               bg-{{ $accentColor }}/10 text-{{ $accentColor }} hover:bg-{{ $accentColor }}/20
               border border-{{ $accentColor }}/20 transition-all duration-150 cursor-pointer"
    >
        <x-icon :name="$icon" class="w-3 h-3 opacity-70" />
        <span class="max-w-[180px] truncate">{{ $integration->name ?? $serviceName }}</span>
        @if ($showStatus)
            <span class="w-2 h-2 rounded-full bg-{{ $statusColor }} {{ $isProcessing ? 'animate-pulse' : '' }}"></span>
        @endif
    </a>

    {{-- Popover Card --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95 translate-y-1"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        x-cloak
        @mouseenter="keepOpen()"
        @click.outside="open = false"
        class="absolute z-50 mt-2 left-0"
        style="min-width: 300px; max-width: 360px;"
    >
        <div class="card bg-base-100 shadow-xl border border-{{ $accentColor }}/30 overflow-hidden">
            {{-- Accent bar --}}
            <div class="h-1 bg-gradient-to-r from-{{ $accentColor }} to-{{ $accentColor }}/50"></div>

            <div class="card-body p-4 gap-3">
                {{-- Header with icon and service name --}}
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-xl bg-{{ $accentColor }}/10 flex items-center justify-center flex-shrink-0 ring-2 ring-{{ $accentColor }}/20">
                        <x-icon :name="$icon" class="w-6 h-6 text-{{ $accentColor }}" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <h4 class="font-bold text-base leading-tight">
                            {{ $integration->name ?? $serviceName }}
                        </h4>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="badge badge-{{ $accentColor }} badge-outline badge-xs">{{ $serviceName }}</span>
                            <span class="badge badge-ghost badge-xs">{{ $instanceDisplayName }}</span>
                        </div>
                    </div>
                </div>

                {{-- Status indicator --}}
                <div class="flex items-center gap-2 p-2 rounded-lg bg-{{ $statusColor }}/10 border border-{{ $statusColor }}/20">
                    <x-icon :name="$statusIcon" class="w-4 h-4 text-{{ $statusColor }} {{ $isProcessing ? 'animate-spin' : '' }}" />
                    <span class="text-sm font-medium text-{{ $statusColor }}">{{ $statusText }}</span>
                    @if ($isPaused)
                        <span class="text-xs text-base-content/50 ml-auto">Updates suspended</span>
                    @elseif ($isStale)
                        <span class="text-xs text-base-content/50 ml-auto">No recent data</span>
                    @elseif ($isProcessing)
                        <span class="text-xs text-base-content/50 ml-auto">Syncing...</span>
                    @endif
                </div>

                {{-- Timing info --}}
                <div class="space-y-2 text-sm">
                    @if ($lastUpdate)
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-base-content/60 flex items-center gap-1">
                                <x-icon name="fas.clock-rotate-left" class="w-3 h-3" />
                                Last updated
                            </span>
                            <span class="font-medium">{{ $lastUpdate->diffForHumans() }}</span>
                        </div>
                    @endif

                    @if ($nextUpdate && !$isPaused)
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-base-content/60 flex items-center gap-1">
                                <x-icon name="fas.clock" class="w-3 h-3" />
                                Next update
                            </span>
                            <span class="font-medium">{{ $nextUpdate->diffForHumans() }}</span>
                        </div>
                    @endif

                    @if ($integration->useSchedule())
                        @php $scheduleSummary = $integration->getScheduleSummary(); @endphp
                        @if ($scheduleSummary)
                            <div class="flex items-center gap-2 text-xs text-base-content/50">
                                <x-icon name="fas.calendar-days" class="w-3 h-3" />
                                <span>{{ $scheduleSummary }}</span>
                            </div>
                        @endif
                    @else
                        <div class="flex items-center gap-2 text-xs text-base-content/50">
                            <x-icon name="fas.repeat" class="w-3 h-3" />
                            <span>Every {{ $integration->getUpdateFrequencyMinutes() }} minutes</span>
                        </div>
                    @endif
                </div>

                {{-- Service type badge --}}
                <div class="flex items-center gap-2 text-xs text-base-content/50">
                    <span class="badge badge-ghost badge-xs capitalize">{{ $serviceType }}</span>
                    <span class="badge badge-ghost badge-xs capitalize">{{ $domain }}</span>
                </div>

                {{-- Action footer --}}
                <div class="flex items-center gap-2 pt-3 border-t border-base-300">
                    <a
                        href="{{ route('integrations.configure', $integration) }}"
                        wire:navigate
                        @click.stop
                        class="btn btn-{{ $accentColor }} btn-sm flex-1 gap-1"
                    >
                        <x-icon name="fas.cog" class="w-3 h-3" />
                        Configure
                    </a>
                    <a
                        href="{{ route('integrations.index') }}"
                        wire:navigate
                        @click.stop
                        class="btn btn-ghost btn-sm btn-square"
                        title="All Integrations"
                    >
                        <x-icon name="fas.list" class="w-4 h-4" />
                    </a>
                </div>
            </div>
        </div>
    </div>
</span>
