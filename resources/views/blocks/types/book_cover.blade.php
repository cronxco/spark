@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas.book';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : 'Goodreads';

// Get book information from the target EventObject
$book = $block->event->target;
$bookTitle = $book->title ?? 'Unknown Book';
$authorName = $book->metadata['author'] ?? null;
$authorUrl = $book->metadata['author_url'] ?? null;
$bookUrl = $book->url ?? null;

// Get rating if this is from a reviewed event
$rating = null;
if ($block->event->action === 'reviewed' && $block->event->value) {
    $rating = (int) $block->event->value;
}

// Use Media Library for responsive images with signed URLs
$imageUrl = get_media_url($block, 'downloaded_images');
$mediumUrl = get_media_url($block, 'downloaded_images', 'medium');
$thumbnailUrl = get_media_url($block, 'downloaded_images', 'thumbnail');

// Get action display name
$actionTypes = $pluginClass ? $pluginClass::getActionTypes() : [];
$actionType = $actionTypes[$block->event->action] ?? null;
$actionDisplayName = $actionType['display_name'] ?? ucfirst(str_replace('_', ' ', $block->event->action));
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        {{-- Header: Date and Action --}}
        <div class="flex items-center justify-between gap-2">
            <div class="badge badge-warning badge-outline badge-sm gap-1">
                <x-icon name="{{ $icon }}" class="w-3 h-3" />
                {{ $actionDisplayName }}
            </div>
            <x-uk-date :date="$block->time" :show-time="true" class="text-xs flex-shrink-0" />
        </div>

        {{-- Book Cover Display (portrait aspect ratio for books) --}}
        @if ($mediumUrl || $imageUrl)
        <div class="w-full aspect-[2/3] rounded-lg overflow-hidden bg-base-300 shadow-md">
            <img
                src="{{ $mediumUrl ?: $imageUrl }}"
                alt="{{ $bookTitle }}"
                class="w-full h-full object-cover"
                loading="lazy"
                @if ($thumbnailUrl)
                style="background-image: url('{{ $thumbnailUrl }}'); background-size: cover;"
                @endif
            />
        </div>
        @else
        <div class="w-full aspect-[2/3] rounded-lg overflow-hidden bg-base-300 flex items-center justify-center">
            <x-icon name="fas.book" class="w-16 h-16 text-base-content/30" />
        </div>
        @endif

        {{-- Book Title and Author --}}
        <div class="text-center space-y-1">
            <h3 class="font-semibold text-base leading-snug line-clamp-2">
                @if ($bookUrl)
                    <a href="{{ $bookUrl }}" target="_blank" rel="noopener noreferrer" class="hover:underline">
                        {{ $bookTitle }}
                    </a>
                @else
                    {{ $bookTitle }}
                @endif
            </h3>
            @if ($authorName)
            <div class="text-sm text-base-content/70">
                @if ($authorUrl)
                    <a href="{{ $authorUrl }}" target="_blank" rel="noopener noreferrer" class="hover:underline">
                        {{ $authorName }}
                    </a>
                @else
                    {{ $authorName }}
                @endif
            </div>
            @endif
        </div>

        {{-- Star Rating (if reviewed) --}}
        @if ($rating)
        <div class="flex items-center justify-center gap-1">
            @for ($i = 1; $i <= 5; $i++)
                <x-icon name="fas.star" class="w-4 h-4 {{ $i <= $rating ? 'text-warning' : 'text-base-content/20' }}" />
            @endfor
        </div>
        @endif

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="fas.image" class="w-3 h-3" />
                Book Cover
            </div>

            <div class="flex-1"></div>

            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-xs btn-square">
                    <x-icon name="fas.ellipsis-vertical" class="w-4 h-4" />
                </div>
                <ul tabindex="0" class="dropdown-content menu bg-base-100 rounded-box z-[1] w-52 p-2 shadow-lg border border-base-300">
                    <li>
                        <a href="{{ route('blocks.show', $block) }}" wire:navigate>
                            <x-icon name="fas.eye" class="w-4 h-4" />
                            View Block
                        </a>
                    </li>
                    @if ($bookUrl)
                    <li>
                        <a href="{{ $bookUrl }}" target="_blank" rel="noopener noreferrer">
                            <x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" />
                            Open in Goodreads
                        </a>
                    </li>
                    @endif
                    @if ($imageUrl)
                    <li>
                        <a href="{{ $imageUrl }}" target="_blank" rel="noopener noreferrer">
                            <x-icon name="fas.image" class="w-4 h-4" />
                            View Full Image
                        </a>
                    </li>
                    @endif
                </ul>
            </div>
        </div>
    </div>
</div>
