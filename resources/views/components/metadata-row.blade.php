@props(['label', 'value' => null, 'copyable' => false])

<div class="flex justify-between items-start gap-4 py-2 border-b border-base-200 last:border-0 group">
    <dt class="text-sm font-medium text-base-content/60 flex-shrink-0">
        {{ $label }}
    </dt>
    <dd class="text-sm text-base-content text-right break-words flex items-center gap-2">
        <span class="flex-1">{{ $value ?? $slot }}</span>
        @if($copyable && ($value || $slot))
            <button
                type="button"
                class="btn btn-ghost btn-xs opacity-60 sm:opacity-0 sm:group-hover:opacity-100 hover:opacity-100 transition-opacity"
                onclick="navigator.clipboard.writeText('{{ addslashes($value ?? '') }}').then(() => {
                    const toast = document.createElement('div');
                    toast.className = 'toast toast-top toast-center z-50';
                    toast.innerHTML = `
                        <div class='alert alert-success shadow-lg'>
                            <svg xmlns='http://www.w3.org/2000/svg' class='stroke-current shrink-0 h-5 w-5' fill='none' viewBox='0 0 24 24'>
                                <path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' />
                            </svg>
                            <span>{{ $label }} copied!</span>
                        </div>
                    `;
                    document.body.appendChild(toast);
                    setTimeout(() => {
                        toast.classList.add('opacity-0');
                        setTimeout(() => toast.remove(), 300);
                    }, 2000);
                })"
                title="Copy {{ strtolower($label) }} to clipboard">
                <x-icon name="o-clipboard" class="w-3 h-3" />
            </button>
        @endif
    </dd>
</div>
