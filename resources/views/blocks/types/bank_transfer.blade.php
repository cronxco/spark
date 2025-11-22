@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas-grip';
$displayName = $pluginClass ? $pluginClass::getDisplayName() : ucfirst($block->event->service);

$counterparty = $block->metadata['counterparty'] ?? 'Unknown';
$sortCode = $block->metadata['sort_code'] ?? null;
$accountNumber = $block->metadata['account_number'] ?? null;
$amount = $block->formatted_value ?? 0;
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

        {{-- Transfer Info --}}
        <div class="space-y-2">
            <div class="flex items-center justify-between">
                <div class="text-sm text-base-content/70">To</div>
                <div class="font-semibold">{{ $counterparty }}</div>
            </div>
            @if ($sortCode || $accountNumber)
            <div class="flex items-center justify-between text-xs text-base-content/60">
                <div> </div>
                <div class="font-mono">
                    @if ($sortCode){{ $sortCode }}@endif
                    @if ($sortCode && $accountNumber) • @endif
                    @if ($accountNumber)****{{ substr($accountNumber, -4) }}@endif
                </div>
            </div>
            @endif
            <div class="flex items-center justify-between pt-2 border-t border-base-300">
                <div class="text-sm text-base-content/70"> </div>
                <div class="text-2xl font-bold">£{{ number_format($amount, 2) }}</div>
            </div>
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="{{ $icon }}" class="w-3 h-3" />
                Bank Transfer
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
                        <a href="{{ route('events.show', $block->event) }}" wire:navigate>
                            <x-icon name="fas-calendar" class="w-4 h-4" />
                            View Event
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
