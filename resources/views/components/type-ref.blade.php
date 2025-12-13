@props(['type', 'concept' => null, 'service' => null, 'variant' => 'badge', 'href' => null])

@php
use App\Integrations\PluginRegistry;

// Handle null/empty type gracefully
if (!$type) {
    return;
}

// Get service info if provided for icon/color
$icon = 'fas.tag';
$accentColor = 'primary';
$domain = 'knowledge';

if ($service) {
    $pluginClass = PluginRegistry::getPlugin($service);
    if ($pluginClass) {
        // Try to get object type icon
        $objectTypes = $pluginClass::getObjectTypes();
        if (isset($objectTypes[$type]['icon'])) {
            $icon = $objectTypes[$type]['icon'];
        } else {
            $icon = $pluginClass::getIcon();
        }
        $accentColor = $pluginClass::getAccentColor();
        $domain = $pluginClass::getDomain();
    }
}

// Domain-based colors
$domainColors = [
    'health' => 'success',
    'money' => 'warning',
    'media' => 'info',
    'knowledge' => 'primary',
    'online' => 'accent',
];
$domainColor = $domainColors[$domain] ?? 'primary';

// Type display
$typeLabel = Str::headline($type);

// Generate href if not provided - link to filtered admin objects
$defaultHref = '/admin/objects?type=' . urlencode($type);
if ($concept) {
    $defaultHref .= '&concept=' . urlencode($concept);
}
$linkHref = $href ?? $defaultHref;

// Get object count for this type
$objectCount = \App\Models\EventObject::where('type', $type)->count();

// Base ID for this popover
$popoverBaseId = 'type-ref-' . md5($type . ($concept ?? '') . ($service ?? ''));
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
                   bg-{{ $domainColor }}/10 text-{{ $domainColor }} hover:bg-{{ $domainColor }}/20
                   border border-{{ $domainColor }}/20 transition-all duration-150 cursor-pointer"
        >
            <x-icon :name="$icon" class="w-3 h-3 opacity-70" />
            <span>{{ $typeLabel }}</span>
        </a>

        {{-- Mobile: popover trigger only --}}
        <button
            x-show="isMobile"
            type="button"
            @click="toggle()"
            class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-sm font-medium
                   bg-{{ $domainColor }}/10 text-{{ $domainColor }} hover:bg-{{ $domainColor }}/20
                   border border-{{ $domainColor }}/20 transition-all duration-150 cursor-pointer"
        >
            <x-icon :name="$icon" class="w-3 h-3 opacity-70" />
            <span>{{ $typeLabel }}</span>
        </button>
    @else
        {{-- Trigger: Text variant (plain text with hover) --}}
        {{-- Desktop: navigable link --}}
        <a
            x-show="!isMobile"
            href="{{ $linkHref }}"
            wire:navigate
            class="font-medium hover:text-{{ $domainColor }} transition-colors cursor-pointer"
        >{{ $typeLabel }}</a>

        {{-- Mobile: popover trigger only --}}
        <button
            x-show="isMobile"
            type="button"
            @click="toggle()"
            class="font-medium hover:text-{{ $domainColor }} transition-colors cursor-pointer bg-transparent border-0 p-0"
        >{{ $typeLabel }}</button>
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
                {{-- Header with icon and type name --}}
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-xl bg-{{ $domainColor }}/10 flex items-center justify-center flex-shrink-0 ring-2 ring-{{ $domainColor }}/20">
                        <x-icon :name="$icon" class="w-6 h-6 text-{{ $domainColor }}" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <h4 class="font-bold text-base leading-tight">
                            {{ $typeLabel }}
                        </h4>
                        <div class="badge badge-ghost badge-xs mt-1">Object Type</div>
                    </div>
                </div>

                {{-- Type stats --}}
                <div class="space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-base-content/60">Total objects</span>
                        <span class="font-medium">{{ number_format($objectCount) }}</span>
                    </div>

                    @if ($concept)
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-base-content/60">Concept</span>
                            <span class="badge badge-ghost badge-xs">{{ Str::headline($concept) }}</span>
                        </div>
                    @endif

                    @if ($service)
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-base-content/60">Service</span>
                            <span class="badge badge-{{ $accentColor }} badge-xs">
                                {{ PluginRegistry::getPlugin($service)::getDisplayName() }}
                            </span>
                        </div>
                    @endif
                </div>

                {{-- Recent objects preview --}}
                @php
                    $recentObjects = \App\Models\EventObject::where('type', $type)
                        ->orderBy('time', 'desc')
                        ->limit(3)
                        ->get();
                @endphp

                @if ($recentObjects->count() > 0)
                    <div class="space-y-1">
                        <div class="text-xs text-base-content/60 font-medium">Recent objects:</div>
                        @foreach ($recentObjects as $obj)
                            <div class="text-sm truncate text-base-content/80">
                                • {{ $obj->title }}
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Footer with link to filter objects --}}
                <div class="pt-3 border-t border-base-300">
                    <a
                        href="{{ $linkHref }}"
                        wire:navigate
                        @click.stop
                        class="btn btn-{{ $domainColor }} btn-sm w-full gap-1"
                    >
                        <x-icon name="fas.arrow-right" class="w-3 h-3" />
                        View All {{ $typeLabel }} Objects
                    </a>
                </div>
            </div>
        </div>
    </div>
</span>
