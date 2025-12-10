@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas.book';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : 'Goodreads';
$accentColor = $pluginClass ? $pluginClass::getAccentColor() : 'warning';

// Get book information from the target EventObject
$book = $block->event->target;
$bookTitle = $book->title ?? $block->title ?? 'Unknown Book';
$bookUrl = $book->url ?? null;

// Get metadata from both block and target object
$metadata = array_merge($block->metadata ?? [], $book->metadata ?? []);
$authorName = $metadata['author'] ?? null;
$authorUrl = $metadata['author_url'] ?? null;
$numPages = $metadata['num_pages'] ?? null;
$publishedYear = $metadata['published_year'] ?? null;
$isbn = $metadata['isbn'] ?? null;
$averageRating = $metadata['average_rating'] ?? null;
$userRating = $metadata['user_rating'] ?? null;
$seriesName = $metadata['series_name'] ?? null;
$seriesNumber = $metadata['series_number'] ?? null;
$dateRead = $metadata['date_read'] ?? null;
$currentShelf = $metadata['current_shelf'] ?? null;
$currentProgress = $metadata['current_progress'] ?? null;

// If book has been read (date_read is set), treat as 100% progress
if ($dateRead && ($currentProgress === null || $currentProgress < 100)) {
    $currentProgress = 100;
}

// Get action display name
$actionTypes = $pluginClass ? $pluginClass::getActionTypes() : [];
$actionType = $actionTypes[$block->event->action] ?? null;
$actionDisplayName = $actionType['display_name'] ?? ucfirst(str_replace('_', ' ', $block->event->action));
$actionIcon = $actionType['icon'] ?? 'fas.book';

// Use Media Library for cover image
$imageUrl = get_media_url($block, 'downloaded_images');
$hasImage = $block->getFirstMedia('downloaded_images') !== null;

// Format shelf name
$shelfDisplay = $currentShelf ? ucfirst(str_replace('-', ' ', $currentShelf)) : null;
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        {{-- Header: Action and Date --}}
        <div class="flex items-center justify-between gap-2">
            <div class="badge badge-{{ $accentColor }} badge-outline badge-sm gap-1">
                <x-icon name="{{ $actionIcon }}" class="w-3 h-3" />
                {{ $actionDisplayName }}
            </div>
            <x-uk-date :date="$block->time" :show-time="true" class="text-xs flex-shrink-0" />
        </div>

        {{-- Book Cover and Info Layout --}}
        <div class="flex gap-4">
            {{-- Book Cover --}}
            <div class="flex-shrink-0 w-24">
                @if ($hasImage)
                <div class="w-full aspect-[2/3] rounded-lg overflow-hidden bg-base-300 shadow-md">
                    {!! render_media_responsive($block, 'downloaded_images', [
                        'alt' => $bookTitle,
                        'class' => 'w-full h-full object-cover',
                        'loading' => 'lazy',
                    ]) !!}
                </div>
                @else
                <div class="w-full aspect-[2/3] rounded-lg overflow-hidden bg-base-300 flex items-center justify-center">
                    <x-icon name="fas.book" class="w-8 h-8 text-base-content/30" />
                </div>
                @endif
            </div>

            {{-- Book Details --}}
            <div class="flex-1 min-w-0 space-y-2">
                {{-- Title --}}
                <h3 class="font-semibold text-base leading-tight line-clamp-2">
                    @if ($bookUrl)
                        <a href="{{ $bookUrl }}" target="_blank" rel="noopener noreferrer" class="hover:underline">
                            {{ $bookTitle }}
                        </a>
                    @else
                        {{ $bookTitle }}
                    @endif
                </h3>

                {{-- Author --}}
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

                {{-- Series Info --}}
                @if ($seriesName)
                <div class="text-xs text-base-content/60 flex items-center gap-1">
                    <x-icon name="fas.layer-group" class="w-3 h-3" />
                    {{ $seriesName }}@if ($seriesNumber) #{{ $seriesNumber }}@endif
                </div>
                @endif

                {{-- Metadata Grid --}}
                <div class="grid grid-cols-2 gap-2 text-xs">
                    @if ($publishedYear)
                    <div class="flex items-center gap-1 text-base-content/70">
                        <x-icon name="fas.calendar" class="w-3 h-3" />
                        {{ $publishedYear }}
                    </div>
                    @endif

                    @if ($numPages)
                    <div class="flex items-center gap-1 text-base-content/70">
                        <x-icon name="fas.file-lines" class="w-3 h-3" />
                        {{ number_format($numPages) }} pages
                    </div>
                    @endif

                    @if ($userRating)
                    <div class="flex items-center gap-1 text-base-content/70">
                        <x-icon name="fas.star" class="w-3 h-3" />
                        You: {{ number_format($userRating, 1) }}
                    </div>
                    @endif

                    @if ($averageRating)
                    <div class="flex items-center gap-1 text-base-content/70">
                        <x-icon name="fas.star-half-stroke" class="w-3 h-3" />
                        Avg: {{ number_format($averageRating, 2) }}
                    </div>
                    @endif
                </div>

                {{-- Reading Status / Progress --}}
                @if ($currentProgress !== null && $currentProgress < 100)
                    {{-- Progress Bar (for currently reading) --}}
                    <div class="space-y-1">
                        <div class="flex items-center justify-between text-xs text-base-content/60">
                            <span>Reading Progress</span>
                            <span>{{ $currentProgress }}%</span>
                        </div>
                        <progress class="progress progress-{{ $accentColor }} w-full h-2" value="{{ $currentProgress }}" max="100"></progress>
                    </div>
                @elseif ($currentShelf === 'to-read')

                @elseif ($currentShelf === 'read' && $dateRead)
                    {{-- Read Badge with Date --}}
                    <div class="badge badge-sm badge-outline badge-{{ $accentColor }} gap-1">
                        <x-icon name="fas.book-bookmark" class="w-3 h-3" />
                        Read <x-uk-date :date="\Carbon\Carbon::parse($dateRead)" :show-time="false" class="ml-1" />
                    </div>
                @endif
            </div>
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="{{ $icon }}" class="w-3 h-3" />
                {{ $displayName }}
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
                    @if ($authorUrl)
                    <li>
                        <a href="{{ $authorUrl }}" target="_blank" rel="noopener noreferrer">
                            <x-icon name="fas.pen-nib" class="w-4 h-4" />
                            View Author
                        </a>
                    </li>
                    @endif
                    @if ($imageUrl)
                    <li>
                        <a href="{{ $imageUrl }}" target="_blank" rel="noopener noreferrer">
                            <x-icon name="fas.image" class="w-4 h-4" />
                            View Cover Image
                        </a>
                    </li>
                    @endif
                </ul>
            </div>
        </div>
    </div>
</div>
