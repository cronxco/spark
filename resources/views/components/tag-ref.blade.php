@props(['tag', 'full' => false, 'fill' => false, 'size' => 'sm', 'showCounts' => false, 'text' => null])

@php
// Handle both objects and arrays
$isObject = is_object($tag);
$isArray = is_array($tag);

if ($isObject) {
    $tagName = is_array($tag->name ?? null) ? ($tag->name['en'] ?? $tag->name[0] ?? '') : (string)($tag->name ?? '');
    $tagType = $tag->type ?? null;
    $tagId = $tag->id ?? null;
    $tagSlug = is_array($tag->slug ?? null) ? ($tag->slug['en'] ?? $tag->slug[0] ?? null) : ($tag->slug ?? null);
    // Get counts if available (from withCount on the tag model)
    $eventsCount = $tag->events_count ?? null;
    $objectsCount = $tag->objects_count ?? null;
} elseif ($isArray) {
    $tagName = is_array($tag['name'] ?? null) ? ($tag['name']['en'] ?? $tag['name'][0] ?? '') : (string)($tag['name'] ?? '');
    $tagType = $tag['type'] ?? null;
    $tagId = $tag['id'] ?? null;
    $tagSlug = is_array($tag['slug'] ?? null) ? ($tag['slug']['en'] ?? $tag['slug'][0] ?? null) : ($tag['slug'] ?? null);
    $eventsCount = $tag['events_count'] ?? null;
    $objectsCount = $tag['objects_count'] ?? null;
} else {
    $tagName = (string)$tag;
    $tagType = null;
    $tagId = null;
    $tagSlug = null;
    $eventsCount = null;
    $objectsCount = null;
}

// Truncate long tag names
$displayName = $tagName;
if (!$full && mb_strlen($tagName) > 20) {
    $spacePos = mb_strpos($tagName, ' ');
    $cut = $spacePos === false || $spacePos > 20;
    $wrapped = wordwrap($tagName, 20, ';;', $cut);
    $parts = explode(';;', $wrapped);
    $displayName = $parts[0] . '...';
}

// Determine color based on tag type with more variety
$colorMap = [
    // Money-related tags
    'transaction_category' => 'warning',
    'transaction_type' => 'warning',
    'transaction_status' => 'warning',
    'transaction_scheme' => 'warning',
    'transaction_currency' => 'warning',
    'balance_type' => 'warning',
    'card_pan' => 'warning',
    'decline_reason' => 'error',
    'merchant_country' => 'info',
    'merchant_category' => 'info',

    // Music/Media tags
    'music_artist' => 'secondary',
    'music_album' => 'accent',
    'spotify_context' => 'success',

    // People
    'person' => 'secondary',

    // Emoji/Visual
    'emoji' => 'warning',
    'merchant_emoji' => 'warning',

    // System
    'spark' => 'primary',
    'karakeep' => 'info',
];

$color = $colorMap[$tagType] ?? 'info';

// Determine icon based on tag type
$iconMap = [
    'transaction_category' => 'fas.folder',
    'transaction_type' => 'fas.exchange-alt',
    'transaction_status' => 'fas.check-circle',
    'transaction_scheme' => 'fas.credit-card',
    'transaction_currency' => 'fas.coins',
    'merchant_category' => 'fas.store',
    'merchant_country' => 'fas.globe',
    'music_artist' => 'fas.microphone',
    'music_album' => 'fas.compact-disc',
    'spotify_context' => 'fab.spotify',
    'person' => 'fas.user',
    'emoji' => 'fas.face-smile',
    'merchant_emoji' => 'fas.face-smile',
    'spark' => 'fas.star',
    'karakeep' => 'fas.bookmark',
];
$tagIcon = $iconMap[$tagType] ?? 'fas.tag';

// Badge styling
$badgeClass = "badge-{$color}";
if (!$fill) {
    $badgeClass .= ' badge-outline';
}

$sizeClass = match($size) {
    'xs' => 'badge-xs',
    'sm' => 'badge-sm',
    'md' => 'badge-md',
    'lg' => 'badge-lg',
    default => 'badge-sm',
};
$badgeClass .= ' ' . $sizeClass;

// Check if tag is linkable
$isLinkable = $tagType && $tagSlug && $tagId;

// Generate unique ID for this popover
$popoverId = 'spark-tag-' . ($tagId ?? md5($tagName . ($tagType ?? '')));
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
    @keydown.escape="open = false"
    @popover-opening.window="closeIfNotMe($event)"
    class="relative inline-block"
>
    {{-- Trigger: The tag badge --}}
    @if ($isLinkable)
        <a
            href="{{ route('tags.show', [$tagType, $tagSlug, $tagId]) }}"
            wire:navigate
            @click="if (isMobile) { $event.preventDefault(); $event.stopPropagation(); toggle(); }"
            class="badge {{ $badgeClass }} gap-1 cursor-pointer hover:brightness-110 transition-all"
        >
            <x-icon :name="$tagIcon" class="w-3 h-3 opacity-70" />
            <span>{!! $text ?? $displayName !!}</span>
        </a>
    @else
        <span
            @click.stop="toggle()"
            class="badge {{ $badgeClass }} gap-1 cursor-default"
        >
            <x-icon :name="$tagIcon" class="w-3 h-3 opacity-70" />
            <span>{!! $text ?? $displayName !!}</span>
        </span>
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
        style="min-width: 200px; max-width: 280px;"
    >
        <div class="card bg-base-100 shadow-xl border border-{{ $color }}/30 overflow-hidden">
            {{-- Accent bar --}}
            <div class="h-1 bg-gradient-to-r from-{{ $color }} to-{{ $color }}/50"></div>

            <div class="card-body p-4 gap-3">
                {{-- Header with icon --}}
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-{{ $color }}/10 flex items-center justify-center flex-shrink-0 ring-2 ring-{{ $color }}/20">
                        <x-icon :name="$tagIcon" class="w-5 h-5 text-{{ $color }}" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-xs text-base-content/60 uppercase tracking-wide font-medium">
                            {{ Str::headline($tagType ?? 'Tag') }}
                        </div>
                        <div class="font-bold text-base leading-tight truncate" title="{{ $tagName }}">
                            {{ $tagName }}
                        </div>
                    </div>
                </div>

                {{-- Counts (if available and requested) --}}
                @if ($showCounts && ($eventsCount !== null || $objectsCount !== null))
                    <div class="flex items-center gap-4 text-sm text-base-content/60">
                        @if ($eventsCount !== null)
                            <div class="flex items-center gap-1">
                                <x-icon name="fas.bolt" class="w-3 h-3" />
                                <span>{{ number_format($eventsCount) }} event{{ $eventsCount !== 1 ? 's' : '' }}</span>
                            </div>
                        @endif
                        @if ($objectsCount !== null)
                            <div class="flex items-center gap-1">
                                <x-icon name="fas.cube" class="w-3 h-3" />
                                <span>{{ number_format($objectsCount) }} object{{ $objectsCount !== 1 ? 's' : '' }}</span>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Action footer --}}
                @if ($isLinkable)
                    <div class="pt-3 border-t border-base-300">
                        <a
                            href="{{ route('tags.show', [$tagType, $tagSlug, $tagId]) }}"
                            wire:navigate
                            @click.stop
                            class="btn btn-{{ $color }} btn-sm w-full gap-1"
                        >
                            <x-icon name="fas.arrow-right" class="w-3 h-3" />
                            View Tagged Items
                        </a>
                    </div>
                @else
                    <div class="text-xs text-base-content/50 text-center pt-2 border-t border-base-300">
                        This tag is not linkable
                    </div>
                @endif
            </div>
        </div>
    </div>
</span>
