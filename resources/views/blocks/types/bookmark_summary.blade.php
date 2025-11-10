@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'o-squares-2x2';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);

$summary = $block->metadata['content'] ?? '';
$wordCount = str_word_count($summary);
$model = $block->metadata['model'] ?? 'AI';
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
                @if (isset($block->metadata['model']))
                <div class="badge badge-ghost badge-xs">{{ $block->metadata['model'] }}</div>
                @endif
                <x-uk-date :date="$block->time" :show-time="true" class="text-xs" />
            </div>
        </div>

        {{-- AI Summary Display --}}
        <div class="relative">
            <div class="bg-gradient-to-br from-warning/5 to-warning/25 rounded-lg p-3 border border-warning/50">
                <p class="text-sm text-base-content/80 leading-relaxed">
                    {{ $summary }}
                </p>
            </div>
            {{-- AI Badge --}}
            <div class="absolute -top-2 -right-2 bg-warning rounded-full p-1.5 shadow">
                <x-icon name="o-sparkles" class="w-3 h-3 text-warning-content" />
            </div>
        </div>

        {{-- Stats --}}
        <div class="flex items-center gap-4 text-xs text-base-content/60">
            <div class="flex items-center gap-1">
                <x-icon name="o-document-text" class="w-3 h-3" />
                {{ $wordCount }} words
            </div>
            @if (isset($block->metadata['generated_at']))
            <div class="flex items-center gap-1">
                <x-icon name="o-clock" class="w-3 h-3" />
                Generated {{ \Carbon\Carbon::parse($block->metadata['generated_at'])->diffForHumans() }}
            </div>
            @endif
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="o-sparkles" class="w-3 h-3" />
                AI Summary
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
                        <button onclick="navigator.clipboard.writeText('{{ addslashes($summary) }}')">
                            <x-icon name="o-clipboard" class="w-4 h-4" />
                            Copy Summary
                        </button>
                    </li>
                    @if ($block->url)
                    <li>
                        <a href="{{ $block->url }}" target="_blank" rel="noopener noreferrer">
                            <x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" />
                            Open URL
                        </a>
                    </li>
                    @endif
                </ul>
            </div>
        </div>
    </div>
</div>