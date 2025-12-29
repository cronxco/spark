@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas.users';

$people = $block->metadata['people'] ?? [];
@endphp

<div class="card bg-base-200 shadow hover:shadow-lg transition-all">
    <div class="card-body p-4 gap-3">
        {{-- Header --}}
        <div class="flex items-center justify-between gap-2">
            <h3 class="font-semibold text-base leading-snug flex-1">
                People in Cluster
            </h3>
            <x-uk-date :date="$block->time" :show-time="true" class="text-xs flex-shrink-0" />
        </div>

        {{-- People list --}}
        @if (count($people) > 0)
            <div class="space-y-2">
                @foreach ($people as $person)
                    <div class="flex items-center justify-between bg-base-300 rounded-lg px-3 py-2">
                        <div class="flex items-center gap-2">
                            <div class="avatar placeholder">
                                <div class="bg-primary text-primary-content rounded-full w-8 h-8 flex items-center justify-center">
                                    <span class="text-xs font-semibold">{{ mb_substr($person['name'], 0, 1, 'UTF-8') }}</span>
                                </div>
                            </div>
                            <span class="font-medium">{{ $person['name'] }}</span>
                        </div>
                        <div class="badge badge-sm gap-1">
                            <x-icon name="fas.image" class="w-2.5 h-2.5" />
                            {{ $person['photo_count'] }}
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="text-center text-base-content/60 py-4">
                <x-icon name="fas.users" class="w-8 h-8 mx-auto mb-2 opacity-50" />
                <p class="text-sm">No people detected in this cluster</p>
            </div>
        @endif

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="{{ $icon }}" class="w-3 h-3" />
                {{ count($people) }} {{ Str::plural('person', count($people)) }}
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
                        <a href="{{ route('events.show', $block->event) }}" wire:navigate>
                            <x-icon name="fas.images" class="w-4 h-4" />
                            View Photo Cluster
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
