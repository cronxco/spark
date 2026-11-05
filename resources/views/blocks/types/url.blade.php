@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas.grip';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);

$url = $block->url ?? $block->title;
$parsedUrl = parse_url($url);
$domain = $parsedUrl['host'] ?? '';
$domain = str_replace('www.', '', $domain);
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        {{-- Header: Domain and Date --}}
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2 flex-1 min-w-0">
                <h3 class="font-semibold text-base leading-snug flex-1 truncate">
                    {{ $domain }}
                </h3>
            </div>
            <x-uk-date :date="$block->time" :show-time="true" class="text-xs flex-shrink-0" />
        </div>

        {{-- URL Display --}}
        <div class="bg-base-100 rounded-lg p-3 border border-base-300">
            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="text-sm text-primary hover:underline break-all">
                {{ $url }}
            </a>
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="{{ $icon }}" class="w-3 h-3" />
                {{ $displayName }} URL
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
                    <li>
                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer">
                            <x-icon name="o-arrow-top-right-on-square" class="w-4 h-4" />
                            Open URL
                        </a>
                    </li>
                    <li>
                        <a href="javascript:void(0)" onclick="navigator.clipboard.writeText('{{ addslashes($url) }}')">
                            <x-icon name="o-clipboard" class="w-4 h-4" />
                            Copy URL
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
