@props(['action', 'service', 'variant' => 'badge', 'href' => null, 'showService' => false, 'text' => null])

@php
use App\Integrations\PluginRegistry;

// Handle null/empty action gracefully
if (!$action || !$service) {
    return;
}

// Get plugin info
$pluginClass = PluginRegistry::getPlugin($service);
$serviceIcon = $pluginClass ? $pluginClass::getIcon() : 'fas.bolt';
$serviceName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($service);
$accentColor = $pluginClass ? $pluginClass::getAccentColor() : 'primary';
$domain = $pluginClass ? $pluginClass::getDomain() : 'knowledge';

// Get action type info from plugin
$actionTypes = $pluginClass ? $pluginClass::getActionTypes() : [];
$actionInfo = $actionTypes[$action] ?? null;
$actionIcon = $actionInfo['icon'] ?? $serviceIcon;
$actionDisplay = $actionInfo['display_name'] ?? Str::headline($action);
$actionDescription = $actionInfo['description'] ?? null;
$valueUnit = $actionInfo['value_unit'] ?? null;
$displayWithObject = $actionInfo['display_with_object'] ?? false;
$isHidden = $actionInfo['hidden'] ?? false;

// Domain-based colors
$domainColors = [
    'health' => 'success',
    'money' => 'warning',
    'media' => 'info',
    'knowledge' => 'primary',
    'online' => 'accent',
];
$domainColor = $domainColors[$domain] ?? 'primary';

// Base ID for this popover (unique suffix added via JavaScript)
$popoverBaseId = 'action-ref-' . md5($service . '-' . $action);
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
    @click="toggle()"
    @keydown.escape="open = false"
    @popover-opening.window="closeIfNotMe($event)"
    class="relative inline-block"
>
    {{-- Trigger: Badge variant (default) --}}
    @if ($variant === 'badge')
    @if ($href)
    <a
        href="{{ $href }}"
        wire:navigate
        @click.stop="if (isMobile) { $event.preventDefault(); toggle(); }"
        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-sm font-medium
               bg-{{ $domainColor }}/10 text-{{ $domainColor }} hover:bg-{{ $domainColor }}/20
               border border-{{ $domainColor }}/20 transition-all duration-150 cursor-pointer"
    >
        <x-icon :name="$actionIcon" class="w-3 h-3 opacity-70" />
        <span class="max-w-[200px] truncate">{!! $text ?? $actionDisplay !!}</span>
        @if ($showService)
            <span class="badge badge-xs badge-ghost opacity-70">{{ $serviceName }}</span>
        @endif
    </a>
    @else
    <span
        @click.stop="toggle()"
        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-sm font-medium
               bg-{{ $domainColor }}/10 text-{{ $domainColor }}
               border border-{{ $domainColor }}/20 cursor-default"
    >
        <x-icon :name="$actionIcon" class="w-3 h-3 opacity-70" />
        <span class="max-w-[200px] truncate">{!! $text ?? $actionDisplay !!}</span>
        @if ($showService)
            <span class="badge badge-xs badge-ghost opacity-70">{{ $serviceName }}</span>
        @endif
    </span>
    @endif
    @else
    {{-- Trigger: Text variant (plain text with hover) --}}
    @if ($href)
    <a
        href="{{ $href }}"
        wire:navigate
        @click.stop="if (isMobile) { $event.preventDefault(); toggle(); }"
        class="font-medium hover:text-{{ $domainColor }} transition-colors cursor-pointer"
    >{!! $text ?? $actionDisplay !!}</a>
    @else
    <span
        @click.stop="toggle()"
        class="font-medium cursor-default"
    >{!! $text ?? $actionDisplay !!}</span>
    @endif
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
        <div class="card bg-base-100 shadow-xl border border-{{ $domainColor }}/30 overflow-hidden">
            {{-- Accent bar at top --}}
            <div class="h-1 bg-gradient-to-r from-{{ $domainColor }} to-{{ $domainColor }}/50"></div>

            <div class="card-body p-4 gap-3">
                {{-- Header with icon and action name --}}
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-xl bg-{{ $domainColor }}/10 flex items-center justify-center flex-shrink-0 ring-2 ring-{{ $domainColor }}/20">
                        <x-icon :name="$actionIcon" class="w-6 h-6 text-{{ $domainColor }}" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <h4 class="font-bold text-base leading-tight">
                            {{ $actionDisplay }}
                        </h4>
                        <div class="flex flex-wrap gap-1 mt-1">
                            <span class="badge badge-{{ $accentColor }} badge-outline badge-xs gap-1">
                                <x-icon :name="$serviceIcon" class="w-2.5 h-2.5" />
                                {{ $serviceName }}
                            </span>
                            <span class="badge badge-ghost badge-xs">{{ Str::headline($domain) }}</span>
                        </div>
                    </div>
                </div>

                {{-- Description --}}
                @if ($actionDescription)
                    <p class="text-sm text-base-content/70 leading-relaxed">
                        {{ $actionDescription }}
                    </p>
                @endif

                {{-- Action details --}}
                <div class="space-y-2 text-sm">
                    {{-- Raw action key --}}
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-base-content/60">Action key</span>
                        <code class="text-xs bg-base-200 px-2 py-0.5 rounded font-mono">{{ $action }}</code>
                    </div>

                    {{-- Value unit if present --}}
                    @if ($valueUnit)
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-base-content/60">Value unit</span>
                            <span class="font-medium">{{ $valueUnit }}</span>
                        </div>
                    @endif

                    {{-- Display with object --}}
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-base-content/60">Shows with object</span>
                        <span class="badge badge-xs {{ $displayWithObject ? 'badge-success' : 'badge-ghost' }}">
                            {{ $displayWithObject ? 'Yes' : 'No' }}
                        </span>
                    </div>

                    {{-- Hidden status --}}
                    @if ($isHidden)
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-base-content/60">Visibility</span>
                            <span class="badge badge-xs badge-warning">Hidden from timeline</span>
                        </div>
                    @endif
                </div>

                {{-- Footer with link to filter events --}}
                @if ($href)
                <div class="pt-3 border-t border-base-300">
                    <a
                        href="{{ $href }}"
                        wire:navigate
                        @click.stop
                        class="btn btn-{{ $domainColor }} btn-sm w-full gap-1"
                    >
                        <x-icon name="fas.arrow-right" class="w-3 h-3" />
                        View Event
                    </a>
                </div>
                @endif
            </div>
        </div>
    </div>
</span>
