@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'o-squares-2x2';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);

$html = $block->metadata['html'] ?? null;
$text = $block->metadata['text'] ?? null;
$excerpt = $block->metadata['excerpt'] ?? null;
$displayText = $excerpt ?? $text;
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        {{-- Header: Title and Date --}}
        <div class="flex items-center justify-between gap-2">
            <h3 class="font-semibold text-base leading-snug flex-1 line-clamp-1">
                <a href="{{ route('blocks.show', $block) }}" wire:navigate class="hover:underline">
                    {{ $block->title }}
                </a>
            </h3>
            <x-uk-date :date="$block->time" :show-time="true" class="text-xs flex-shrink-0" />
        </div>

        {{-- Content Display --}}
        @if ($displayText)
        <div class="bg-base-100 rounded-lg p-3 border border-base-300 max-h-48 overflow-y-auto">
            <p class="text-sm leading-relaxed line-clamp-10">
                {{ $displayText }}
            </p>
        </div>
        @endif

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="o-document-text" class="w-3 h-3" />
                Raw Content
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
                    @if ($displayText)
                    <li>
                        <a href="javascript:void(0)" onclick="navigator.clipboard.writeText('{{ addslashes($displayText) }}')">
                            <x-icon name="o-clipboard" class="w-4 h-4" />
                            Copy Text
                        </a>
                    </li>
                    @endif
                </ul>
            </div>
        </div>
    </div>
</div>
