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
                class="btn btn-ghost btn-xs opacity-0 group-hover:opacity-100 transition-opacity"
                onclick="navigator.clipboard.writeText('{{ $value ?? $slot }}').then(() => {
                    const toast = document.createElement('div');
                    toast.className = 'toast toast-top toast-center';
                    toast.innerHTML = '<div class=\'alert alert-success\'><span>Copied to clipboard!</span></div>';
                    document.body.appendChild(toast);
                    setTimeout(() => toast.remove(), 2000);
                })"
                title="Copy to clipboard">
                <x-icon name="o-clipboard" class="w-3 h-3" />
            </button>
        @endif
    </dd>
</div>
