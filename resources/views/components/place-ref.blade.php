@props(['place', 'showCategory' => true, 'variant' => 'badge', 'text' => null])

@php
// Handle null places gracefully
if (!$place) {
    return;
}

// Load relationships if needed
$eventsCount = $place->eventsHere()->count();

// Use info color for places (location theme)
$accentColor = 'info';

// Base ID for this popover (unique suffix added via JavaScript)
$popoverBaseId = 'place-ref-' . $place->id;

// Format visit info
$visitCount = $place->visit_count;
$lastVisit = $place->last_visit_at ? Carbon\Carbon::parse($place->last_visit_at) : null;
$category = $place->category;
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
    @if ($variant === 'badge')
        {{-- Badge Variant --}}
        {{-- Desktop: navigable link --}}
        <a
            x-show="!isMobile"
            href="{{ route('places.show', $place) }}"
            wire:navigate
            class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-sm font-medium
                   bg-{{ $accentColor }}/10 text-{{ $accentColor }} hover:bg-{{ $accentColor }}/20
                   border border-{{ $accentColor }}/20 transition-all duration-150 cursor-pointer"
        >
            <x-icon name="fas.map-marker-alt" class="w-3 h-3 opacity-70" />
            <span class="max-w-[200px] truncate">{!! $text ?? $place->title !!}</span>
            @if ($showCategory && $category)
                <span class="badge badge-xs badge-ghost opacity-70">{{ ucfirst($category) }}</span>
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
            <x-icon name="fas.map-marker-alt" class="w-3 h-3 opacity-70" />
            <span class="max-w-[200px] truncate">{!! $text ?? $place->title !!}</span>
            @if ($showCategory && $category)
                <span class="badge badge-xs badge-ghost opacity-70">{{ ucfirst($category) }}</span>
            @endif
        </button>
    @else
        {{-- Text Variant --}}
        {{-- Desktop: navigable link --}}
        <a
            x-show="!isMobile"
            href="{{ route('places.show', $place) }}"
            wire:navigate
            class="inline-flex items-center gap-1 text-sm font-medium text-{{ $accentColor }} hover:underline cursor-pointer"
        >
            <x-icon name="fas.map-marker-alt" class="w-3 h-3 opacity-70" />
            <span>{!! $text ?? $place->title !!}</span>
        </a>

        {{-- Mobile: popover trigger only --}}
        <button
            x-show="isMobile"
            type="button"
            @click="toggle()"
            class="inline-flex items-center gap-1 text-sm font-medium text-{{ $accentColor }} hover:underline cursor-pointer"
        >
            <x-icon name="fas.map-marker-alt" class="w-3 h-3 opacity-70" />
            <span>{!! $text ?? $place->title !!}</span>
        </button>
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
        style="min-width: 320px; max-width: 380px;"
    >
        <div class="card bg-base-100 shadow-xl border border-{{ $accentColor }}/30 overflow-hidden">
            {{-- Accent bar with gradient --}}
            <div class="h-1 bg-gradient-to-r from-{{ $accentColor }} to-{{ $accentColor }}/50"></div>

            <div class="card-body p-4 gap-3">
                {{-- Header: Location icon and category --}}
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <div class="badge badge-{{ $accentColor }} gap-1">
                            <x-icon name="fas.map-marker-alt" class="w-3 h-3" />
                            Place
                        </div>
                        @if ($category)
                            <div class="badge badge-ghost badge-sm">{{ ucfirst($category) }}</div>
                        @endif
                        @if ($place->is_favorite)
                            <div class="badge badge-warning badge-sm gap-1">
                                <x-icon name="fas.star" class="w-3 h-3" />
                                Favorite
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Place title --}}
                <div class="text-lg font-bold leading-tight">
                    {{ $place->title }}
                </div>

                {{-- Address --}}
                @if ($place->location_address)
                    <div class="flex items-start gap-2 text-sm text-base-content/70">
                        <x-icon name="fas.location-dot" class="w-4 h-4 mt-0.5 flex-shrink-0" />
                        <span class="line-clamp-2">{{ $place->location_address }}</span>
                    </div>
                @endif

                {{-- Visit stats --}}
                <div class="flex items-center gap-4 text-xs text-base-content/60">
                    @if ($visitCount > 0)
                        <div class="flex items-center gap-1">
                            <x-icon name="fas.eye" class="w-3 h-3" />
                            <span>{{ $visitCount }} visit{{ $visitCount !== 1 ? 's' : '' }}</span>
                        </div>
                    @endif
                    @if ($lastVisit)
                        <div class="flex items-center gap-1">
                            <x-icon name="fas.clock" class="w-3 h-3" />
                            <span>{{ $lastVisit->diffForHumans() }}</span>
                        </div>
                    @endif
                    @if ($eventsCount > 0)
                        <div class="flex items-center gap-1">
                            <x-icon name="fas.calendar-check" class="w-3 h-3" />
                            <span>{{ $eventsCount }} event{{ $eventsCount !== 1 ? 's' : '' }}</span>
                        </div>
                    @endif
                </div>

                {{-- Action footer --}}
                <div class="flex items-center gap-2 pt-3 border-t border-base-300">
                    <a
                        href="{{ route('places.show', $place) }}"
                        wire:navigate
                        @click.stop
                        class="btn btn-{{ $accentColor }} btn-sm flex-1 gap-1"
                    >
                        <x-icon name="fas.arrow-right" class="w-3 h-3" />
                        View Place
                    </a>
                    @if ($place->latitude && $place->longitude)
                        <a
                            href="https://www.google.com/maps/search/?api=1&query={{ $place->latitude }},{{ $place->longitude }}"
                            target="_blank"
                            rel="noopener"
                            @click.stop
                            class="btn btn-ghost btn-sm btn-square"
                            title="View on Map"
                        >
                            <x-icon name="fas.map" class="w-4 h-4" />
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</span>
