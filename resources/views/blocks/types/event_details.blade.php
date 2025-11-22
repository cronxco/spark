@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas.calendar';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);

$status = $block->metadata['status'] ?? null;
$creator = $block->metadata['creator'] ?? null;
$organizer = $block->metadata['organizer'] ?? null;
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

        {{-- Details Display --}}
        <div class="space-y-2 text-sm">
            @if ($status)
            <div class="flex items-center justify-between">
                <span class="text-base-content/70">Status</span>
                <span class="badge badge-sm {{ $status === 'confirmed' ? 'badge-success' : 'badge-ghost' }}">
                    {{ ucfirst($status) }}
                </span>
            </div>
            @endif
            @if (is_array($organizer) && isset($organizer['email']))
            <div class="flex items-center justify-between">
                <span class="text-base-content/70">Organizer</span>
                <span class="text-right truncate ml-2">{{ $organizer['displayName'] ?? $organizer['email'] }}</span>
            </div>
            @elseif (is_string($organizer))
            <div class="flex items-center justify-between">
                <span class="text-base-content/70">Organizer</span>
                <span class="text-right truncate ml-2">{{ $organizer }}</span>
            </div>
            @endif
            @if (is_array($creator) && isset($creator['email']) && $creator['email'] !== ($organizer['email'] ?? null))
            <div class="flex items-center justify-between">
                <span class="text-base-content/70">Creator</span>
                <span class="text-right truncate ml-2">{{ $creator['displayName'] ?? $creator['email'] }}</span>
            </div>
            @endif
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="{{ $icon }}" class="w-3 h-3" />
                Event Details
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