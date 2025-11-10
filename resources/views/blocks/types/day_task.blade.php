@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'o-squares-2x2';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);

$lineNumber = $block->metadata['line_number'] ?? null;
$checked = $block->metadata['checked'] ?? false;
$documentId = $block->metadata['outline_document_id'] ?? null;
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        {{-- Header: Title and Date --}}
        <div class="flex items-center justify-between gap-2">
            <h3 class="font-semibold text-base leading-snug flex-1 line-clamp-1">
                <a href="{{ route('blocks.show', $block) }}" wire:navigate class="hover:underline">
                    {!! Str::of($block->title)->markdown()->trim() !!}
                </a>
            </h3>
            <x-uk-date :date="$block->time" :show-time="false" class="text-xs flex-shrink-0" />
        </div>

        {{-- Task Display --}}
        <div class="flex items-start gap-3 py-2">
            <div class="flex-shrink-0 mt-1">
                @if ($checked)
                <div class="w-6 h-6 rounded-full bg-primary flex items-center justify-center">
                    <x-icon name="o-check" class="w-4 h-4 text-primary-content" />
                </div>
                @else
                <div class="w-6 h-6 rounded-full border-2 border-base-content/30"></div>
                @endif
            </div>
            <div class="flex-1">
                <div class="{{ $checked ? 'line-through text-base-content/50' : '' }}">
                    {!! Str::of($block->title)->markdown()->trim() !!}
                </div>
                @if ($lineNumber)
                <div class="text-xs text-base-content/60 mt-1">
                    Line {{ $lineNumber }}
                </div>
                @endif
            </div>
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="o-calendar" class="w-3 h-3" />
                Daily Task
            </div>

            <div class="flex-1"></div>

            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-xs btn-square">
                    <x-icon name="o-ellipsis-vertical" class="w-4 h-4" />
                </div>
                <ul tabindex="0" class="dropdown-content menu bg-base-100 rounded-box z-[1] w-52 p-2 shadow-lg border border-base-300">
                    <li>
                        <a href="{{ route('blocks.show', $block) }}" wire:navigate>
                            <x-icon name="o-eye" class="w-4 h-4" />
                            View Block
                        </a>
                    </li>
                    @if ($block->url)
                    <li>
                        <a href="{{ $block->url }}" target="_blank" rel="noopener noreferrer">
                            <x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" />
                            Open in Outline
                        </a>
                    </li>
                    @endif
                </ul>
            </div>
        </div>
    </div>
</div>