@props(['object', 'showType' => false, 'variant' => 'badge', 'href' => null, 'text' => null])

@php
use App\Integrations\PluginRegistry;

// Handle null objects gracefully
if (!$object) {
    return;
}

// Get media thumbnail URL
$thumbnailUrl = get_media_temporary_url($object, 'article_images', 'thumbnail', 60)
    ?? get_media_temporary_url($object, 'downloaded_images', 'thumbnail', 60)
    ?? get_media_temporary_url($object, 'screenshots', 'thumbnail', 60)
    ?? $object->media_url;

// Content preview
$contentPreview = $object->content ? Str::limit(strip_tags($object->content), 100) : null;

// Get type info from metadata if available
$typeDisplay = Str::headline($object->type ?? 'Object');
$conceptDisplay = Str::headline($object->concept ?? '');

// Base ID for this popover (unique suffix added via JavaScript)
$popoverBaseId = 'object-ref-' . $object->id;

// Try to get icon from plugin object types config
$objectIcon = 'fas.cube'; // default
$objectTypes = [];

// Find the plugin that defines this object type
foreach (PluginRegistry::getAllPlugins() as $pluginClass) {
    $types = $pluginClass::getObjectTypes();
    if (isset($types[$object->type])) {
        $objectTypes = $types[$object->type];
        $objectIcon = $objectTypes['icon'] ?? 'fas.cube';
        break;
    }
}

// Determine accent color based on concept
$accentColors = [
    'user' => 'secondary',
    'person' => 'secondary',
    'account' => 'warning',
    'money' => 'warning',
    'media' => 'info',
    'bookmark' => 'primary',
    'document' => 'primary',
    'day' => 'accent',
];
$accentColor = 'secondary';
foreach ($accentColors as $key => $color) {
    if (str_contains(strtolower($object->concept ?? ''), $key)) {
        $accentColor = $color;
        break;
    }
}

// Event counts
$eventCount = $object->actorEvents()->count() + $object->targetEvents()->count();
$relationshipCount = $object->allRelationships()->count();

// Default href to object show page
$linkHref = $href ?? route('objects.show', $object);
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
    <a
        href="{{ $linkHref }}"
        wire:navigate
        @click.stop="if (isMobile) { $event.preventDefault(); toggle(); }"
        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-sm font-medium
               bg-{{ $accentColor }}/10 text-{{ $accentColor }} hover:bg-{{ $accentColor }}/20
               border border-{{ $accentColor }}/20 transition-all duration-150 cursor-pointer"
    >
        <x-icon :name="$objectIcon" class="w-3 h-3 opacity-70" />
        <span class="max-w-[200px] truncate">{!! $text ?? $object->title !!}</span>
        @if ($showType && $object->type)
            <span class="badge badge-xs badge-ghost opacity-70">{{ $typeDisplay }}</span>
        @endif
    </a>
    @else
    {{-- Trigger: Text variant (plain text with hover) --}}
    <a
        href="{{ $linkHref }}"
        wire:navigate
        @click.stop="if (isMobile) { $event.preventDefault(); toggle(); }"
        class="font-medium hover:text-{{ $accentColor }} transition-colors cursor-pointer"
    >{!! $text ?? $object->title !!}</a>
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
            {{-- Accent bar at top --}}
            <div class="h-1 bg-gradient-to-r from-{{ $accentColor }} to-{{ $accentColor }}/50"></div>

            <div class="card-body p-4 gap-3">
                {{-- Header with thumbnail/icon and title --}}
                <div class="flex gap-3">
                    {{-- Thumbnail or Icon --}}
                    @if ($thumbnailUrl)
                        <div class="w-16 h-16 rounded-lg overflow-hidden bg-base-300 flex-shrink-0 ring-2 ring-{{ $accentColor }}/20">
                            <img src="{{ $thumbnailUrl }}" alt="{{ $object->title }}" class="w-full h-full object-cover">
                        </div>
                    @else
                        <div class="w-16 h-16 rounded-lg bg-{{ $accentColor }}/10 flex items-center justify-center flex-shrink-0 ring-2 ring-{{ $accentColor }}/20">
                            <x-icon :name="$objectIcon" class="w-8 h-8 text-{{ $accentColor }}" />
                        </div>
                    @endif

                    {{-- Title and type info --}}
                    <div class="flex-1 min-w-0">
                        <h4 class="font-bold text-base leading-tight line-clamp-2 mb-1">
                            {{ $object->title }}
                        </h4>
                        <div class="flex flex-wrap gap-1">
                            @if ($object->type)
                                <span class="badge badge-{{ $accentColor }} badge-outline badge-xs gap-1">
                                    <x-icon :name="$objectIcon" class="w-2.5 h-2.5" />
                                    {{ $typeDisplay }}
                                </span>
                            @endif
                            @if ($conceptDisplay)
                                <span class="badge badge-ghost badge-xs">{{ $conceptDisplay }}</span>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Content preview --}}
                @if ($contentPreview)
                    <p class="text-sm text-base-content/70 line-clamp-2 leading-relaxed">
                        {{ $contentPreview }}
                    </p>
                @endif

                {{-- Stats row --}}
                <div class="flex items-center gap-4 text-xs text-base-content/60">
                    @if ($eventCount > 0)
                        <div class="flex items-center gap-1">
                            <x-icon name="fas.bolt" class="w-3 h-3" />
                            <span>{{ $eventCount }} event{{ $eventCount !== 1 ? 's' : '' }}</span>
                        </div>
                    @endif
                    @if ($relationshipCount > 0)
                        <div class="flex items-center gap-1">
                            <x-icon name="fas.link" class="w-3 h-3" />
                            <span>{{ $relationshipCount }} link{{ $relationshipCount !== 1 ? 's' : '' }}</span>
                        </div>
                    @endif
                    @if ($object->time)
                        <div class="flex items-center gap-1 ml-auto">
                            <x-icon name="fas.clock" class="w-3 h-3" />
                            <span>{{ $object->time->diffForHumans() }}</span>
                        </div>
                    @endif
                </div>

                {{-- Tags preview (if any) --}}
                @if ($object->tags->count() > 0)
                    <div class="flex flex-wrap gap-1">
                        @foreach ($object->tags->take(4) as $tag)
                            <span class="badge badge-ghost badge-xs">{{ $tag->name }}</span>
                        @endforeach
                        @if ($object->tags->count() > 4)
                            <span class="badge badge-ghost badge-xs">+{{ $object->tags->count() - 4 }} more</span>
                        @endif
                    </div>
                @endif

                {{-- URL indicator --}}
                @if ($object->url)
                    <div class="flex items-center gap-1 text-xs text-base-content/50 truncate">
                        <x-icon name="fas.link" class="w-3 h-3 flex-shrink-0" />
                        <span class="truncate">{{ parse_url($object->url, PHP_URL_HOST) }}</span>
                    </div>
                @endif

                {{-- Action footer --}}
                <div class="flex items-center gap-2 pt-3 border-t border-base-300">
                    <a
                        href="{{ route('objects.show', $object) }}"
                        wire:navigate
                        @click.stop
                        class="btn btn-{{ $accentColor }} btn-sm flex-1 gap-1"
                    >
                        <x-icon name="fas.arrow-right" class="w-3 h-3" />
                        View Object
                    </a>
                    @if ($object->url)
                        <a
                            href="{{ $object->url }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            @click.stop
                            class="btn btn-ghost btn-sm btn-square"
                            title="Open URL"
                        >
                            <x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" />
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</span>
