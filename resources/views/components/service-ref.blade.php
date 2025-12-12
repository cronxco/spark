@props(['service', 'variant' => 'badge', 'href' => null, 'showIcon' => true, 'showLabel' => true, 'size' => 'md'])

@php
use App\Integrations\PluginRegistry;

// Handle null/empty service gracefully
if (!$service) {
    return;
}

// Get plugin info
$pluginClass = PluginRegistry::getPlugin($service);
if (!$pluginClass) {
    return;
}

$serviceName = $pluginClass::getDisplayName();
$icon = $pluginClass::getIcon();
$accentColor = $pluginClass::getAccentColor();
$domain = $pluginClass::getDomain();
$serviceType = $pluginClass::getServiceType();

// Domain-based colors
$domainColors = [
    'health' => 'success',
    'money' => 'warning',
    'media' => 'info',
    'knowledge' => 'primary',
    'online' => 'accent',
];
$domainColor = $domainColors[$domain] ?? 'primary';

// Service type labels
$serviceTypeLabels = [
    'oauth' => 'OAuth',
    'webhook' => 'Webhook',
    'manual' => 'Manual',
    'apikey' => 'API Key',
];
$serviceTypeLabel = $serviceTypeLabels[$serviceType] ?? ucfirst($serviceType);

// Generate href if not provided - link to plugin show page
$defaultHref = route('plugins.show', ['service' => $service]);
$linkHref = $href ?? $defaultHref;

// Get integration count
$integrationCount = \App\Models\IntegrationGroup::where('service', $service)->count();

// Base ID for this popover
$popoverBaseId = 'service-ref-' . md5($service);
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
    @keydown.escape="open = false"
    @popover-opening.window="closeIfNotMe($event)"
    class="relative inline-block"
>
    {{-- Trigger: Badge variant (default) --}}
    @if ($variant === 'badge')
        {{-- Desktop: navigable link --}}
        <a
            x-show="!isMobile"
            href="{{ $linkHref }}"
            wire:navigate
            class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-sm font-medium
                   bg-{{ $accentColor }}/10 text-{{ $accentColor }} hover:bg-{{ $accentColor }}/20
                   border border-{{ $accentColor }}/20 transition-all duration-150 cursor-pointer"
        >
            @if ($showIcon)
                <x-icon :name="$icon" class="w-3 h-3 opacity-70" />
            @endif
            @if ($showLabel)
                <span>{{ $serviceName }}</span>
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
            @if ($showIcon)
                <x-icon :name="$icon" class="w-3 h-3 opacity-70" />
            @endif
            @if ($showLabel)
                <span>{{ $serviceName }}</span>
            @endif
        </button>
    @elseif ($variant === 'logo')
        {{-- Logo variant: just the icon in a circle --}}
        <a
            x-show="!isMobile"
            href="{{ $linkHref }}"
            wire:navigate
            class="w-10 h-10 rounded-full bg-{{ $accentColor }}/10 flex items-center justify-center
                   hover:bg-{{ $accentColor }}/20 border border-{{ $accentColor }}/20
                   transition-all duration-150 cursor-pointer"
        >
            <x-icon :name="$icon" class="w-5 h-5 text-{{ $accentColor }}" />
        </a>

        <button
            x-show="isMobile"
            type="button"
            @click="toggle()"
            class="w-10 h-10 rounded-full bg-{{ $accentColor }}/10 flex items-center justify-center
                   hover:bg-{{ $accentColor }}/20 border border-{{ $accentColor }}/20
                   transition-all duration-150 cursor-pointer"
        >
            <x-icon :name="$icon" class="w-5 h-5 text-{{ $accentColor }}" />
        </button>
    @else
        {{-- Trigger: Text variant (plain text with hover) --}}
        {{-- Desktop: navigable link --}}
        <a
            x-show="!isMobile"
            href="{{ $linkHref }}"
            wire:navigate
            class="font-medium hover:text-{{ $accentColor }} transition-colors cursor-pointer"
        >{{ $serviceName }}</a>

        {{-- Mobile: popover trigger only --}}
        <button
            x-show="isMobile"
            type="button"
            @click="toggle()"
            class="font-medium hover:text-{{ $accentColor }} transition-colors cursor-pointer bg-transparent border-0 p-0"
        >{{ $serviceName }}</button>
    @endif

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
        style="min-width: 280px; max-width: 340px;"
    >
        <div class="card bg-base-100 shadow-xl border border-{{ $accentColor }}/30 overflow-hidden">
            {{-- Accent bar at top --}}
            <div class="h-1 bg-gradient-to-r from-{{ $accentColor }} to-{{ $accentColor }}/50"></div>

            <div class="card-body p-4 gap-3">
                {{-- Header with icon and service name --}}
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-xl bg-{{ $accentColor }}/10 flex items-center justify-center flex-shrink-0 ring-2 ring-{{ $accentColor }}/20">
                        <x-icon :name="$icon" class="w-6 h-6 text-{{ $accentColor }}" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <h4 class="font-bold text-base leading-tight">
                            {{ $serviceName }}
                        </h4>
                        <div class="flex flex-wrap gap-1 mt-1">
                            <span class="badge badge-{{ $domainColor }} badge-outline badge-xs">
                                {{ Str::headline($domain) }}
                            </span>
                            <span class="badge badge-ghost badge-xs">{{ $serviceTypeLabel }}</span>
                        </div>
                    </div>
                </div>

                {{-- Service stats --}}
                <div class="space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-base-content/60">Connected instances</span>
                        <span class="font-medium">{{ number_format($integrationCount) }}</span>
                    </div>
                </div>

                {{-- Footer with link to service details --}}
                <div class="pt-3 border-t border-base-300">
                    <a
                        href="{{ $linkHref }}"
                        wire:navigate
                        @click.stop
                        class="btn btn-{{ $accentColor }} btn-sm w-full gap-1"
                    >
                        <x-icon name="fas.arrow-right" class="w-3 h-3" />
                        View Service Details
                    </a>
                </div>
            </div>
        </div>
    </div>
</span>
