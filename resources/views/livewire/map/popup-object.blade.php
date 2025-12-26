@props(['object'])

@php
use App\Integrations\PluginRegistry;

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

// Load tags
$object->loadMissing('tags');
@endphp

<div class="card bg-base-100 shadow-xl border border-{{ $accentColor }}/30 overflow-hidden" style="min-width: 300px; max-width: 350px;">
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

        {{-- Location info --}}
        @if ($object->location_address)
            <div class="flex items-start gap-2 p-2 bg-base-200 rounded text-sm">
                <x-icon name="fas.location-dot" class="w-4 h-4 text-{{ $accentColor }} mt-0.5 flex-shrink-0" />
                <span class="text-base-content/80">{{ $object->location_address }}</span>
            </div>
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
                    class="btn btn-ghost btn-sm btn-square"
                    title="Open URL"
                >
                    <x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" />
                </a>
            @endif
        </div>
    </div>
</div>
