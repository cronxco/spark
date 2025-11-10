@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'o-squares-2x2';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);

$summary = $block->metadata['summary'] ?? '';
$charCount = mb_strlen($summary);
$wordCount = $block->metadata['word_count'] ?? str_word_count($summary);
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        {{-- Header with Twitter-style badge --}}
        <div class="flex items-center justify-between gap-2">
            <div class="badge badge-info badge-outline badge-sm gap-1">
                <x-icon name="o-chat-bubble-left-right" class="w-3 h-3" />
                Tweet Summary
            </div>
            <div class="flex items-center gap-2">
                <div class="badge badge-ghost badge-xs">{{ $charCount }}/280</div>
                <x-uk-date :date="$block->time" :show-time="true" class="text-xs" />
            </div>
        </div>

        {{-- Tweet-style content box --}}
        <div class="bg-base-100 rounded-lg p-3 border border-base-300">
            <p class="text-sm leading-relaxed">
                {{ $summary }}
            </p>
        </div>

        {{-- Stats --}}
        <div class="flex items-center gap-4 text-xs text-base-content/60">
            <div class="flex items-center gap-1">
                <x-icon name="o-chat-bubble-left" class="w-3 h-3" />
                {{ $wordCount }} words
            </div>
            @if(isset($block->metadata['model']))
                <div class="flex items-center gap-1">
                    <x-icon name="o-cpu-chip" class="w-3 h-3" />
                    {{ $block->metadata['model'] }}
                </div>
            @endif
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-info badge-xs gap-1">
                <x-icon :name="$icon" class="w-2.5 h-2.5" />
                {{ $displayName }}
            </div>

            <a href="{{ route('events.show', $block->event) }}"
               wire:navigate
               class="text-xs text-base-content/50 hover:text-base-content/80 transition-colors flex-1 truncate">
                {{ Str::limit($block->event->action, 30) }}
            </a>

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
                </ul>
            </div>
        </div>
    </div>
</div>
