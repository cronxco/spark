@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas.book';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : 'Goodreads';

// Get author information from block metadata or target object
$authorName = $block->title;
$authorUrl = $block->metadata['author_url'] ?? $block->url ?? null;

// Get book information from the target EventObject
$book = $block->event->target;
$bookTitle = $book->title ?? 'Unknown Book';
$bookUrl = $book->url ?? null;

// Get action display name
$actionTypes = $pluginClass ? $pluginClass::getActionTypes() : [];
$actionType = $actionTypes[$block->event->action] ?? null;
$actionDisplayName = $actionType['display_name'] ?? ucfirst(str_replace('_', ' ', $block->event->action));
$actionIcon = $actionType['icon'] ?? 'fas.book-open';
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        {{-- Header: Date and Action --}}
        <div class="flex items-center justify-between gap-2">
            <div class="badge badge-warning badge-outline badge-sm gap-1">
                <x-icon name="{{ $actionIcon }}" class="w-3 h-3" />
                {{ $actionDisplayName }}
            </div>
            <x-uk-date :date="$block->time" :show-time="true" class="text-xs flex-shrink-0" />
        </div>

        {{-- Author and Book Info --}}
        <div class="space-y-3">
            {{-- Author Icon and Name --}}
            <div class="flex items-center gap-3 p-3 bg-base-300 rounded-lg">
                <div class="flex-shrink-0 w-12 h-12 rounded-full bg-warning/20 flex items-center justify-center">
                    <x-icon name="fas.pen-nib" class="w-6 h-6 text-warning" />
                </div>
                <div class="flex-1">
                    <div class="text-xs text-base-content/60 mb-1">Author</div>
                    <div class="font-semibold text-base">
                        @if ($authorUrl)
                            <a href="{{ $authorUrl }}" target="_blank" rel="noopener noreferrer" class="hover:underline">
                                {{ $authorName }}
                            </a>
                        @else
                            {{ $authorName }}
                        @endif
                    </div>
                </div>
            </div>

            {{-- Book Title --}}
            <div class="text-sm">
                <div class="text-base-content/60 mb-1">Book</div>
                <div class="font-medium text-base-content">
                    @if ($bookUrl)
                        <a href="{{ $bookUrl }}" target="_blank" rel="noopener noreferrer" class="hover:underline">
                            {{ $bookTitle }}
                        </a>
                    @else
                        {{ $bookTitle }}
                    @endif
                </div>
            </div>
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="{{ $icon }}" class="w-3 h-3" />
                Author Info
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
                    @if ($authorUrl)
                    <li>
                        <a href="{{ $authorUrl }}" target="_blank" rel="noopener noreferrer">
                            <x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" />
                            View on Goodreads
                        </a>
                    </li>
                    @endif
                    @if ($bookUrl)
                    <li>
                        <a href="{{ $bookUrl }}" target="_blank" rel="noopener noreferrer">
                            <x-icon name="fas.book" class="w-4 h-4" />
                            View Book
                        </a>
                    </li>
                    @endif
                </ul>
            </div>
        </div>
    </div>
</div>
