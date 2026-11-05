@props(['block'])

@php
use App\Integrations\PluginRegistry;

$pluginClass = PluginRegistry::getPlugin($block->event->service);
$icon = $pluginClass ? $pluginClass::getIcon() : 'fas.receipt';

$paymentMethod = $block->metadata['payment_method'] ?? null;
$cardLast4 = $block->metadata['card_last_4'] ?? null;
$receiptNumber = $block->metadata['receipt_number'] ?? null;
$terminalId = $block->metadata['terminal_id'] ?? null;

// Determine payment icon based on method
$paymentIcon = match (strtolower($paymentMethod ?? '')) {
    'card', 'credit', 'debit', 'credit_card', 'debit_card' => 'fas.credit-card',
    'cash' => 'fas.money-bill-wave',
    'contactless', 'apple_pay', 'google_pay' => 'fas.wifi',
    'bank_transfer', 'transfer' => 'fas.building-columns',
    default => 'fas.credit-card',
};

// Format payment method for display
$paymentMethodDisplay = match (strtolower($paymentMethod ?? '')) {
    'card', 'credit_card' => 'Credit Card',
    'debit_card' => 'Debit Card',
    'cash' => 'Cash',
    'contactless' => 'Contactless',
    'apple_pay' => 'Apple Pay',
    'google_pay' => 'Google Pay',
    'bank_transfer', 'transfer' => 'Bank Transfer',
    default => $paymentMethod ? ucwords(str_replace('_', ' ', $paymentMethod)) : 'Card',
};
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

        {{-- Receipt Payment Display - White thermal paper style --}}
        <div class="bg-white dark:bg-neutral-900 rounded-lg p-4 font-mono text-sm border border-neutral-200 dark:border-neutral-700 shadow-inner">
            {{-- Payment method with icon --}}
            <div class="flex items-center gap-3 mb-3">
                <div class="w-10 h-10 rounded-lg bg-neutral-100 dark:bg-neutral-800 flex items-center justify-center">
                    <x-icon name="{{ $paymentIcon }}" class="w-5 h-5 text-neutral-600 dark:text-neutral-300" />
                </div>
                <div>
                    <div class="font-semibold text-neutral-800 dark:text-neutral-100">
                        {{ $paymentMethodDisplay }}
                    </div>
                    @if ($cardLast4)
                    <div class="text-xs text-neutral-500 dark:text-neutral-400 tracking-wider">
                        •••• {{ $cardLast4 }}
                    </div>
                    @endif
                </div>
            </div>

            {{-- Receipt details --}}
            @if ($receiptNumber || $terminalId)
            <div class="border-t border-dashed border-neutral-300 dark:border-neutral-600 pt-3 space-y-1">
                @if ($receiptNumber)
                <div class="flex justify-between text-xs text-neutral-500 dark:text-neutral-400">
                    <span>RECEIPT #</span>
                    <span class="tabular-nums">{{ $receiptNumber }}</span>
                </div>
                @endif
                @if ($terminalId)
                <div class="flex justify-between text-xs text-neutral-500 dark:text-neutral-400">
                    <span>TERMINAL</span>
                    <span class="tabular-nums">{{ $terminalId }}</span>
                </div>
                @endif
            </div>
            @endif

            {{-- Approval footer --}}
            <div class="text-center text-xs text-neutral-400 dark:text-neutral-500 mt-3 uppercase tracking-widest">
                ** APPROVED **
            </div>
        </div>

        {{-- Footer --}}
        <div class="flex items-center gap-2 pt-2 border-t border-base-300">
            <div class="badge badge-ghost badge-sm gap-1">
                <x-icon name="{{ $icon }}" class="w-3 h-3" />
                Payment
            </div>

            @if ($cardLast4)
            <div class="badge badge-outline badge-sm font-mono">
                •••• {{ $cardLast4 }}
            </div>
            @endif

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
                            <x-icon name="fas.calendar" class="w-4 h-4" />
                            View Event
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
