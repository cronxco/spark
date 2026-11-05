@props(['integration', 'showStatus' => true, 'text' => null])

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

if ($isPaused) {
    $statusColor = 'warning';
    $statusText = 'Paused';
} elseif ($isStale) {
    $statusColor = 'error';
    $statusText = 'Overdue';
} elseif ($isProcessing) {
    $statusColor = 'info';
    $statusText = 'Processing';
}

// Get timing info
$lastUpdate = $integration->last_successful_update_at;
$nextUpdate = $integration->getNextUpdateTime();

// Base ID for this popover (unique suffix added via JavaScript)
$popoverBaseId = 'integration-ref-' . $integration->id;
@endphp

<span
    x-data="{
        open: false,
        showTimeout: null,
        hideTimeout: null,
        popoverId: '{{ $popoverBaseId }}-' + Math.random().toString(36).substring(2, 10),
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
    @keydown.escape="open = false"
    class="relative inline-block"
>
    {{-- Trigger: The reference link/badge --}}
    {{-- Desktop: navigable link --}}
    <a
        x-show="!isMobile"
        href="{{ route('integrations.configure', $integration) }}"
        wire:navigate
        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-sm font-medium
               bg-{{ $accentColor }}/10 text-{{ $accentColor }} hover:bg-{{ $accentColor }}/20
               border border-{{ $accentColor }}/20 transition-all duration-150 cursor-pointer"
    >
        <x-icon :name="$icon" class="w-3 h-3 opacity-70" />
        <span class="max-w-[180px] truncate">{!! $text ?? ($integration->name ?? $serviceName) !!}</span>
        @if ($showStatus)
            <span class="status status-{{ $statusColor }} status-xs {{ $isProcessing ? 'animate-pulse' : '' }}"></span>
        @endif
    </a>

    {{-- Mobile: popover trigger only --}}
    <button
        x-show="isMobile"
        type="button"
        @click="toggle()"
        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-sm font-medium
               bg-{{ $accentColor }}/10 text-{{ $accentColor }} hover:bg-{{ $accentColor }}/20
               border border-{{ $accentColor }}/20 transition-all duration-150 cursor-pointer"
    >
        <x-icon :name="$icon" class="w-3 h-3 opacity-70" />
        <span class="max-w-[180px] truncate">{!! $text ?? ($integration->name ?? $serviceName) !!}</span>
        @if ($showStatus)
            <span class="status status-{{ $statusColor }} status-xs {{ $isProcessing ? 'animate-pulse' : '' }}"></span>
        @endif
    </button>

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

                {{-- Timing info --}}
                <div class="text-xs space-y-1.5">
                    @if ($lastUpdate)
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-base-content/50">Last updated</span>
                            <x-uk-date :date="$lastUpdate" :show-time="true" class="font-medium" />
                        </div>
                    @endif

                    @if ($nextUpdate && !$isPaused)
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-base-content/50">Next update</span>
                            <x-uk-date :date="$nextUpdate" :show-time="true" class="font-medium" />
                        </div>
                    @endif

                    <div class="flex items-center justify-between gap-2 pt-1.5 border-t border-base-300">
                        @if ($integration->useSchedule())
                            @php $scheduleSummary = $integration->getScheduleSummary(); @endphp
                            @if ($scheduleSummary)
                                <span class="text-base-content/50">{{ $scheduleSummary }}</span>
                            @endif
                        @else
                            <span class="text-base-content/50">Every {{ $integration->getUpdateFrequencyMinutes() }}min</span>
                        @endif

                        <div class="flex items-center gap-1.5">
                            <span class="status status-{{ $statusColor }} {{ $isProcessing ? 'animate-pulse' : '' }}"></span>
                            <span class="font-medium">{{ $statusText }}</span>
                        </div>
                    </div>
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
