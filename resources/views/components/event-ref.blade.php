@props(['event', 'showService' => true, 'text' => null])

@php
use App\Integrations\PluginRegistry;

// Handle null events gracefully
if (!$event) {
    return;
}

// Get plugin info
$pluginClass = PluginRegistry::getPlugin($event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas.bolt';
$serviceName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($event->service);
$accentColor = $pluginClass ? $pluginClass::getAccentColor() : 'primary';
$domain = $pluginClass ? $pluginClass::getDomain() : 'knowledge';

// Get action display
$actionTypes = $pluginClass ? $pluginClass::getActionTypes() : [];
$actionInfo = $actionTypes[$event->action] ?? null;
$actionDisplay = $actionInfo['display_name'] ?? Str::headline($event->action ?? 'Event');

// Domain-based colors
$domainColors = [
    'health' => 'success',
    'money' => 'warning',
    'media' => 'info',
    'knowledge' => 'primary',
    'online' => 'accent',
];
$domainColor = $domainColors[$domain] ?? 'primary';

// Load relationships if needed
$event->loadMissing(['actor', 'target', 'integration']);
$blocksCount = $event->blocks()->count();

// Base ID for this popover (unique suffix added via JavaScript)
$popoverBaseId = 'event-ref-' . $event->id;
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
    {{-- Trigger: The reference link/badge --}}
    <a
        href="{{ route('events.show', $event) }}"
        wire:navigate
        @click="if (isMobile) { $event.preventDefault(); $event.stopPropagation(); toggle(); }"
        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-sm font-medium
               bg-{{ $domainColor }}/10 text-{{ $domainColor }} hover:bg-{{ $domainColor }}/20
               border border-{{ $domainColor }}/20 transition-all duration-150 cursor-pointer"
    >
        <x-icon :name="$icon" class="w-3 h-3 opacity-70" />
        <span class="max-w-[200px] truncate">{!! $text ?? $actionDisplay !!}</span>
        @if ($showService)
            <span class="badge badge-xs badge-ghost opacity-70">{{ $serviceName }}</span>
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
        style="min-width: 340px; max-width: 400px;"
    >
        <div class="card bg-base-100 shadow-xl border border-{{ $domainColor }}/30 overflow-hidden">
            {{-- Accent bar with gradient --}}
            <div class="h-1 bg-gradient-to-r from-{{ $domainColor }} to-{{ $domainColor }}/50"></div>

            <div class="card-body p-4 gap-3">
                {{-- Header: Service badge and timestamp --}}
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <div class="badge badge-{{ $domainColor }} gap-1">
                            <x-icon :name="$icon" class="w-3 h-3" />
                            {{ $serviceName }}
                        </div>
                        <div class="badge badge-ghost badge-sm">{{ Str::headline($domain) }}</div>
                    </div>
                    <div class="text-xs text-base-content/60">
                        {{ $event->time?->diffForHumans() ?? 'No time' }}
                    </div>
                </div>

                {{-- Action headline --}}
                <div class="text-lg font-bold leading-tight">
                    {{ $actionDisplay }}
                </div>

                {{-- Actor → Target flow --}}
                @if ($event->actor || $event->target)
                    <div class="flex items-center gap-2 flex-wrap">
                        @if ($event->actor)
                            <div class="flex items-center gap-1 px-2 py-1 rounded bg-base-200 text-sm">
                                <x-icon name="fas.user" class="w-3 h-3 text-base-content/50" />
                                <span class="font-medium truncate max-w-[120px]">{{ $event->actor->title }}</span>
                            </div>
                        @endif

                        @if ($event->actor && $event->target)
                            <x-icon name="fas.arrow-right" class="w-4 h-4 text-base-content/30" />
                        @endif

                        @if ($event->target)
                            <div class="flex items-center gap-1 px-2 py-1 rounded bg-base-200 text-sm">
                                <x-icon name="fas.bullseye" class="w-3 h-3 text-base-content/50" />
                                <span class="font-medium truncate max-w-[120px]">{{ $event->target->title }}</span>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Value display (if present) --}}
                @if ($event->value !== null)
                    <div class="flex items-baseline gap-2">
                        <span class="text-2xl font-bold text-{{ $domainColor }}">
                            {{ number_format($event->formatted_value, ($event->value_multiplier && $event->value_multiplier > 1) ? 2 : 0) }}
                        </span>
                        @if ($event->value_unit)
                            <span class="text-sm text-base-content/60">{{ $event->value_unit }}</span>
                        @endif
                    </div>
                @endif

                {{-- Stats row --}}
                <div class="flex items-center gap-4 text-xs text-base-content/60">
                    @if ($blocksCount > 0)
                        <div class="flex items-center gap-1">
                            <x-icon name="fas.grip" class="w-3 h-3" />
                            <span>{{ $blocksCount }} block{{ $blocksCount !== 1 ? 's' : '' }}</span>
                        </div>
                    @endif
                    @if ($event->tags->count() > 0)
                        <div class="flex items-center gap-1">
                            <x-icon name="fas.tags" class="w-3 h-3" />
                            <span>{{ $event->tags->count() }} tag{{ $event->tags->count() !== 1 ? 's' : '' }}</span>
                        </div>
                    @endif
                    <div class="flex items-center gap-1 ml-auto">
                        <x-icon name="fas.calendar" class="w-3 h-3" />
                        <span>{{ $event->time?->format('j M Y, H:i') ?? 'No date' }}</span>
                    </div>
                </div>

                {{-- Tags preview --}}
                @if ($event->tags->count() > 0)
                    <div class="flex flex-wrap gap-1">
                        @foreach ($event->tags->take(5) as $tag)
                            <span class="badge badge-ghost badge-xs">{{ $tag->name }}</span>
                        @endforeach
                        @if ($event->tags->count() > 5)
                            <span class="badge badge-ghost badge-xs">+{{ $event->tags->count() - 5 }}</span>
                        @endif
                    </div>
                @endif

                {{-- Action footer --}}
                <div class="flex items-center gap-2 pt-3 border-t border-base-300">
                    <a
                        href="{{ route('events.show', $event) }}"
                        wire:navigate
                        @click.stop
                        class="btn btn-{{ $domainColor }} btn-sm flex-1 gap-1"
                    >
                        <x-icon name="fas.arrow-right" class="w-3 h-3" />
                        View Event
                    </a>
                    @if ($event->actor)
                        <a
                            href="{{ route('objects.show', $event->actor) }}"
                            wire:navigate
                            @click.stop
                            class="btn btn-ghost btn-sm btn-square"
                            title="View Actor"
                        >
                            <x-icon name="fas.user" class="w-4 h-4" />
                        </a>
                    @endif
                    @if ($event->target)
                        <a
                            href="{{ route('objects.show', $event->target) }}"
                            wire:navigate
                            @click.stop
                            class="btn btn-ghost btn-sm btn-square"
                            title="View Target"
                        >
                            <x-icon name="fas.bullseye" class="w-4 h-4" />
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</span>
