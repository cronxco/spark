@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas-grip';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);

$summary = $block->metadata['content'] ?? '';
$wordCount = $block->metadata['word_count'] ?? str_word_count($summary);
$model = $block->metadata['model'] ?? null;
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
            <div class="flex items-center gap-2 flex-shrink-0">
                <x-uk-date :date="$block->time" :show-time="true" class="text-xs" />
            </div>
        </div>

        {{-- AI Summary Display --}}
        <div class="relative">
            <div class="bg-gradient-to-br from-warning/5 to-warning/25 rounded-lg p-3 border border-warning/50">
                <div class="text-base text-base-content/80 leading-relaxed prose prose-base max-w-none">
                    {!! str($summary)->markdown() !!}
                </div>
            </div>
            {{-- AI Badge --}}
            <div class="absolute -top-2 -right-2 bg-warning rounded-full p-1.5 shadow">
                <x-icon name="fas-wand-magic-sparkles" class="w-3 h-3 text-warning-content" />
            </div>
        </div>

        {{-- Stats --}}
        <div class="flex items-center gap-4 text-xs text-base-content/60">
            <div class="flex items-center gap-1">
                <x-icon name="fas-file-lines" class="w-3 h-3" />
                {{ $wordCount }} words
            </div>
            @if (isset($block->metadata['model']))
            <div class="flex items-center gap-1">
                <x-icon name="fas-microchip" class="w-3 h-3" />
                {{ $block->metadata['model'] }}
            </div>
            @endif
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="{{ $icon }}" class="w-3 h-3" />
                Short Summary
            </div>

            <div class="flex-1"></div>

            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-xs btn-square">
                    <x-icon name="fas-ellipsis-vertical" class="w-4 h-4" />
                </div>
                <ul tabindex="0" class="dropdown-content menu bg-base-100 rounded-box z-[1] w-52 p-2 shadow-lg border border-base-300">
                    <li>
                        <a href="{{ route('blocks.show', $block) }}" wire:navigate>
                            <x-icon name="fas-eye" class="w-4 h-4" />
                            View Block
                        </a>
                    </li>
                    <li>
                        <a href="javascript:void(0)" onclick="navigator.clipboard.writeText('{{ addslashes($summary) }}')">
                            <x-icon name="o-clipboard" class="w-4 h-4" />
                            Copy Summary
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>