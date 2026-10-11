<div class="card bg-base-200 shadow">
    <div class="card-body gap-2">
        <h2 class="card-title">Card buffer</h2>
        <p class="text-sm text-base-content/70">The pot that should cover what your credit cards owe. Choosing one only labels it; no money moves.</p>
        <label class="form-control max-w-xs">
            <span class="label-text">Buffer pot</span>
            <select class="select select-bordered select-sm" wire:change="useAsBuffer($event.target.value)">
                <option value="">None</option>
                @foreach ($pots as $pot)
                    <option value="{{ $pot['id'] }}" @selected($current === $pot['id'])>{{ $pot['name'] }}</option>
                @endforeach
            </select>
        </label>
        @error('buffer') <p class="text-error text-sm">{{ $message }}</p> @enderror
    </div>
</div>
