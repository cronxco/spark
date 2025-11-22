@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas-calendar';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);

$time = $block->metadata['time'] ?? null;
$timezone = $block->metadata['timezone'] ?? null;
$isStart = str_contains(strtolower($block->title), 'start');
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
            <x-uk-date :date="$block->time" :show-time="false" class="text-xs flex-shrink-0" />
        </div>

        {{-- Time Display --}}
        @if ($time)
        <div class="flex items-center justify-center gap-3 py-2">
            <x-icon name="{{ $isStart ? 'fas-play' : 'fas-stop' }}" class="w-10 h-10 text-info" />
            <div>
                <div class="text-3xl font-bold">
                    {{ \Carbon\Carbon::parse($time)->format('g:i A') }}
                </div>
                @if ($timezone)
                <div class="text-xs text-base-content/60 mt-1">{{ $timezone }}</div>
                @endif
            </div>
        </div>
        @endif

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="{{ $icon }}" class="w-3 h-3" />
                Event Time
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
                    @if ($block->url)
                    <li>
                        <a href="{{ $block->url }}" target="_blank" rel="noopener noreferrer">
                            <x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" />
                            Open in Calendar
                        </a>
                    </li>
                    @endif
                </ul>
            </div>
        </div>
    </div>
</div>