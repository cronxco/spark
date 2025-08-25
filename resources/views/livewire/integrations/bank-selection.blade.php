<x-layouts.app>
    <div class="container mx-auto px-4 py-8">
        <x-card title="Select Your Bank" shadow class="max-w-2xl mx-auto">
            <div class="space-y-6">
                <div class="text-center">
                    <h2 class="text-2xl font-bold text-base-content mb-2">Connect Your Bank Account</h2>
                    <p class="text-base-content/70">
                        Choose your bank from the list below to continue with the GoCardless integration.
                    </p>
                </div>

                @if(session('success'))
                    <x-alert title="Success" icon="o-check-circle" class="alert-success">
                        {{ session('success') }}
                    </x-alert>
                @endif

                @if(session('error'))
                    <x-alert title="Error" icon="o-exclamation-triangle" class="alert-error">
                        {{ session('error') }}
                    </x-alert>
                @endif

                <form method="POST" action="{{ route('integrations.gocardless.setInstitution', ['group' => $group->id]) }}" class="space-y-4">
                    @csrf
                    
                    <div class="space-y-3">
                        <label for="institution_id" class="block text-sm font-medium">Select Your Bank</label>
                        <select 
                            name="institution_id" 
                            id="institution_id"
                            class="select select-bordered w-full" 
                            required
                        >
                            <option value="">Choose a bank...</option>
                            @foreach(session('gocardless_institutions_'.$group->id, []) as $inst)
                                <option value="{{ $inst['id'] }}" @selected(old('institution_id') == $inst['id'])>
                                    {{ $inst['name'] }}
                                </option>
                            @endforeach
                        </select>
                        
                        @if(empty(session('gocardless_institutions_'.$group->id, [])))
                            <div class="text-sm text-error">
                                <x-icon name="o-exclamation-triangle" class="w-4 h-4 inline mr-1" />
                                Unable to load banks from GoCardless API. This could be due to:
                                <ul class="list-disc list-inside mt-1 ml-2">
                                    <li>API credentials not configured correctly</li>
                                    <li>Network connectivity issues</li>
                                    <li>GoCardless API service disruption</li>
                                </ul>
                                <p class="mt-2">Please check your configuration and try again, or contact support if the issue persists.</p>
                            </div>
                        @else
                            <div class="text-sm text-base-content/70">
                                {{ count(session('gocardless_institutions_'.$group->id, [])) }} banks available for {{ config('services.gocardless.country', 'GB') }}
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center justify-between pt-4">
                        <x-button 
                            type="button"
                            label="Back to Integrations"
                            link="{{ route('integrations.index') }}"
                            class="btn-outline"
                        />
                        
                        <x-button 
                            type="submit" 
                            label="Continue to Bank Login"
                            class="btn-primary"
                            :disabled="empty(session('gocardless_institutions_'.$group->id, []))"
                        />
                    </div>

                    <div class="text-xs text-base-content/70 text-center">
                        <p>If you don't select a bank, the first available institution for your country ({{ config('services.gocardless.country', 'GB') }}) will be used.</p>
                    </div>
                </form>
            </div>
        </x-card>
    </div>
</x-layouts.app>
