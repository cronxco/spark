@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'o-squares-2x2';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);

$articleText = $block->metadata['article_text'] ?? '';
$wordCount = $block->metadata['word_count'] ?? str_word_count($articleText);
$charCount = $block->metadata['char_count'] ?? strlen($articleText);
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
                @if ($model)
                <div class="badge badge-ghost badge-xs">{{ $model }}</div>
                @endif
                <x-uk-date :date="$block->time" :show-time="true" class="text-xs" />
            </div>
        </div>

        {{-- AI Content Display --}}
        <div class="relative">
            <div class="bg-gradient-to-br from-warning/5 to-warning/10 rounded-lg p-3 border border-warning/20 max-h-48 overflow-y-auto">
                <p class="text-sm text-base-content/80 leading-relaxed line-clamp-10">
                    {{ $articleText }}
                </p>
            </div>
            {{-- AI Badge --}}
            <div class="absolute -top-2 -right-2 bg-warning rounded-full p-1.5 shadow">
                <x-icon name="o-cpu-chip" class="w-3 h-3 text-warning-content" />
            </div>
        </div>

        {{-- Stats --}}
        <div class="flex items-center gap-4 text-xs text-base-content/60">
            <div class="flex items-center gap-1">
                <x-icon name="o-document-text" class="w-3 h-3" />
                {{ number_format($wordCount) }} words
            </div>
            <div class="flex items-center gap-1">
                <x-icon name="o-hashtag" class="w-3 h-3" />
                {{ number_format($charCount) }} chars
            </div>
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="{{ $icon }}" class="w-3 h-3" />
                Article
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
                    <li>
                        <a href="javascript:void(0)" onclick="navigator.clipboard.writeText('{{ addslashes($articleText) }}')">
                            <x-icon name="o-clipboard" class="w-4 h-4" />
                            Copy Text
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
