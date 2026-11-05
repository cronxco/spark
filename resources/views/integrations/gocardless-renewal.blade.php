<x-layouts.app>
    <div class="max-w-2xl mx-auto space-y-6 p-6">
        <h1 class="text-2xl font-semibold">Match your renewed accounts</h1>
        <p>Your bank returned different account identifiers. Match each existing account to keep its history and settings. Accounts you mark unavailable will keep their history and remain paused.</p>
        @if ($errors->any())
            <div class="alert alert-error">{{ $errors->first() }}</div>
        @endif
        <form method="POST" action="{{ route('integrations.gocardless.renewal.store', $group) }}" class="space-y-6">
            @csrf
            <input type="hidden" name="reference" value="{{ $pending['reference'] }}">
            @foreach ($oldAccounts as $id => $old)
                <label class="form-control block space-y-2">
                    <span class="font-semibold">{{ $old['title'] }}</span>
                    @if ($old['summary'] !== '')
                        <span class="block text-sm opacity-70">{{ $old['summary'] }}</span>
                    @endif
                    <select name="mapping[{{ $id }}]" class="select select-bordered w-full" required>
                        <option value="">Choose the matching account</option>
                        @foreach ($newAccounts as $newId => $label)
                            <option value="{{ $newId }}" @selected(old('mapping.' . $id) === $newId)>
                                {{ $label }} · {{ substr($newId, 0, 8) }}
                            </option>
                        @endforeach
                        <option value="__missing" @selected(old('mapping.' . $id) === '__missing')>Not available in this consent — keep paused</option>
                    </select>
                </label>
            @endforeach
            <button class="btn btn-primary" type="submit">Finish reconnection</button>
        </form>
    </div>
</x-layouts.app>
