<?php
use App\Integrations\PluginRegistry;
use App\Models\Integration;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;
    
    public array $plugins = [];
    public array $integrationsByService = [];
    
    public function mount(): void
    {
        $this->loadData();
    }
    
    public function loadData(): void
    {
        $this->plugins = PluginRegistry::getAllPlugins()->map(function ($pluginClass) {
            return [
                'identifier' => $pluginClass::getIdentifier(),
                'name' => $pluginClass::getDisplayName(),
                'description' => $pluginClass::getDescription(),
                'type' => $pluginClass::getServiceType(),
                'configuration_schema' => $pluginClass::getConfigurationSchema(),
            ];
        })->toArray();
        
        $userIntegrations = Auth::user()->integrations()->with('user')->get();
        $this->integrationsByService = $userIntegrations->groupBy('service')->map(function ($group) {
            return $group->map(function ($integration) {
                return [
                    'id' => $integration->id,
                    'name' => $integration->name,
                    'service' => $integration->service,
                    'account_id' => $integration->account_id,
                    'configuration' => $integration->configuration,
                    'last_successful_update_at' => $integration->last_successful_update_at ? $integration->last_successful_update_at->toISOString() : null,
                    'user_id' => $integration->user_id,
                ];
            })->toArray();
        })->toArray();
    }
    
    public function initializeIntegration(string $service): void
    {
        $pluginClass = PluginRegistry::getPlugin($service);
        if (!$pluginClass) {
            $this->error('Integration not found.');
            return;
        }
        
        $plugin = new $pluginClass();
        $user = Auth::user();
        
        try {
            $integration = $plugin->initialize($user);
            $this->success('Integration initialized successfully!');
            $this->loadData();
        } catch (\Exception $e) {
            $this->error('Failed to initialize integration. Please try again.');
        }
    }
    
    public function disconnectIntegration(int $integrationId): void
    {
        $integration = Integration::find($integrationId);
        
        if (!$integration || $integration->user_id !== Auth::id()) {
            $this->error('Integration not found.');
            return;
        }
        
        try {
            $integration->delete();
            $this->success('Integration disconnected successfully!');
            $this->loadData();
        } catch (\Exception $e) {
            $this->error('Failed to disconnect integration. Please try again.');
        }
    }
    
    public function copyWebhookUrl(string $url): void
    {
        $this->success('Webhook URL copied to clipboard!');
        $this->dispatch('copy-to-clipboard', url: $url);
    }
    

    
    public function updateIntegrationNameFromIndex($integrationId, string $name): void
    {
        // Convert to string if it's a UUID object
        $integrationId = (string) $integrationId;
        
        $integration = Integration::find($integrationId);
        
        if (!$integration) {
            $this->error('Integration not found.');
            return;
        }
        
        if ((string) $integration->user_id !== (string) Auth::id()) {
            $this->error('Integration not found.');
            return;
        }
        
        if (empty(trim($name))) {
            $this->error('Integration name cannot be empty.');
            return;
        }
        
        try {
            $result = $integration->update(['name' => trim($name)]);
            
            if (!$result) {
                $this->error('Failed to update integration name. Update returned false.');
                return;
            }
            
            // Refresh the model to ensure we have the latest data
            $integration->refresh();
            
            $this->success('Integration name updated successfully!');
            $this->loadData();
        } catch (\Exception $e) {
            $this->error('Failed to update integration name: ' . $e->getMessage());
        }
    }
    

}; ?>

<div class="py-12" 
     x-data 
     @copy-to-clipboard.window="navigator.clipboard.writeText($event.detail.url).catch(err => console.error('Clipboard write failed:', err))">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <x-card title="{{ __('Available Integrations') }}" shadow>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($plugins as $plugin)
                    <x-card shadow separator>
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center space-x-3">
                                <div class="w-8 h-8 rounded-lg bg-base-200 flex items-center justify-center">
                                    @if($plugin['identifier'] === 'github')
                                        <svg class="w-4 h-4 text-base-content" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/>
                                        </svg>
                                    @elseif($plugin['identifier'] === 'slack')
                                        <svg class="w-4 h-4 text-base-content" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M6.194 14.644c0 1.16-.943 2.107-2.107 2.107-1.164 0-2.107-.947-2.107-2.107 0-1.16.943-2.106 2.107-2.106 1.164 0 2.107.946 2.107 2.106zm5.882-2.107c-1.164 0-2.107.946-2.107 2.106 0 1.16.943 2.107 2.107 2.107 1.164 0 2.107-.947 2.107-2.107 0-1.16-.943-2.106-2.107-2.106zm2.107-5.882c0-1.164-.943-2.107-2.107-2.107-1.164 0-2.107.943-2.107 2.107 0 1.164.943 2.107 2.107 2.107 1.164 0 2.107-.943 2.107-2.107zm2.106 5.882c0-1.164-.943-2.107-2.107-2.107-1.164 0-2.107.943-2.107 2.107 0 1.164.943 2.107 2.107 2.107 1.164 0 2.107-.943 2.107-2.107zm5.882-2.107c-1.164 0-2.107.946-2.107 2.106 0 1.16.943 2.107 2.107 2.107 1.164 0 2.107-.947 2.107-2.107 0-1.16-.943-2.106-2.107-2.106z"/>
                                        </svg>
                                    @else
                                        <svg class="w-4 h-4 text-base-content" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M3 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"></path>
                                        </svg>
                                    @endif
                                </div>
                                <div>
                                    <h4 class="text-lg font-semibold">{{ $plugin['name'] }}</h4>
                                    <p class="text-sm text-base-content/70">{{ $plugin['description'] }}</p>
                                </div>
                            </div>
                            <x-badge 
                                :value="ucfirst($plugin['type'])" 
                                :class="$plugin['type'] === 'oauth' ? 'badge-primary' : 'badge-success'"
                            />
                        </div>
                        
                        @php
                            $userIntegrations = $integrationsByService[$plugin['identifier']] ?? [];
                        @endphp
                        
                        @if(count($userIntegrations) > 0)
                            <div class="space-y-3">
                                <div class="flex items-center text-sm text-base-content/70">
                                    <x-icon name="o-check-circle" class="w-4 h-4 mr-2 text-success" />
                                    {{ count($userIntegrations) }} instance{{ count($userIntegrations) > 1 ? 's' : '' }} connected
                                </div>
                                
                                @foreach($userIntegrations as $integration)
                                    <div class="border border-base-300 rounded-lg p-3 bg-base-200">
                                        <div class="flex items-center justify-between mb-2">
                                            <div class="flex items-center space-x-2">
                                                <x-icon name="o-link" class="w-3 h-3 text-base-content/50" />
                                                <div x-data="{ editing: false, name: '{{ $integration['name'] ?: $integration['service'] }}' }" class="flex-1">
                                                    <div x-show="!editing" class="flex items-center space-x-2">
                                                        <span class="text-sm font-medium">{{ $integration['name'] ?: $integration['service'] }}</span>
                                                        <x-button 
                                                            icon="o-pencil" 
                                                            class="btn-ghost btn-xs"
                                                            @click="editing = true; $nextTick(() => $refs.nameInput.focus())"
                                                            title="Edit name"
                                                        />
                                                    </div>
                                                    <div x-show="editing" class="flex items-center space-x-2">
                                                                                                                            <input
                                                                        x-ref="nameInput"
                                                                        type="text"
                                                                        x-model="name"
                                                                        class="input input-xs input-bordered flex-1"
                                                                        @keydown.enter="$wire.updateIntegrationNameFromIndex('{{ $integration['id'] }}', name); editing = false"
                                                                        @keydown.escape="editing = false; name = '{{ $integration['name'] ?: $integration['service'] }}'"
                                                                        placeholder="Enter name"
                                                                    />
                                                                                                                            <x-button
                                                                        icon="o-check"
                                                                        class="btn-ghost btn-xs btn-success"
                                                                        @click="$wire.updateIntegrationNameFromIndex('{{ $integration['id'] }}', name); editing = false"
                                                                        title="Save"
                                                                    />
                                                        <x-button 
                                                            icon="o-x-mark" 
                                                            class="btn-ghost btn-xs btn-error"
                                                            @click="editing = false; name = '{{ $integration['name'] ?: $integration['service'] }}'"
                                                            title="Cancel"
                                                        />
                                                    </div>
                                                </div>
                                            </div>
                                            <x-dropdown>
                                                <x-slot:trigger>
                                                    <x-button icon="o-ellipsis-vertical" class="btn-ghost btn-sm" />
                                                </x-slot:trigger>
                                                <x-menu-item title="{{ __('Configure') }}" link="{{ route('integrations.configure', $integration['id']) }}" />
                                                <x-menu-item 
                                                    title="{{ __('Disconnect') }}" 
                                                    wire:click="disconnectIntegration({{ $integration['id'] }})"
                                                    class="text-error"
                                                />
                                            </x-dropdown>
                                        </div>
                                        
                                        @if($integration['account_id'])
                                            <div class="text-xs text-base-content/70 mb-2">
                                                Account: {{ $integration['account_id'] }}
                                            </div>
                                        @endif
                                        
                                        @if($plugin['type'] === 'oauth')
                                            @php
                                                $frequency = $integration['configuration']['update_frequency_minutes'] ?? 15;
                                                $lastUpdate = $integration['last_successful_update_at'];
                                                $nextUpdate = null; // We'll need to calculate this differently
                                                $needsUpdate = false; // We'll need to calculate this differently
                                            @endphp
                                            
                                            <div class="text-xs text-base-content/70 mb-2">
                                                <div class="flex items-center justify-between">
                                                    <span>Update frequency: {{ $frequency }} minutes</span>
                                                    <x-badge 
                                                        :value="$needsUpdate ? 'Needs update' : 'Up to date'"
                                                        :class="$needsUpdate ? 'badge-warning' : 'badge-success'"
                                                        class="badge-xs"
                                                    />
                                                </div>
                                                
                                                @if($lastUpdate)
                                                    <div class="mt-1">
                                                        Last update: {{ \Carbon\Carbon::parse($lastUpdate)->diffForHumans() }}
                                                    </div>
                                                    @if($nextUpdate)
                                                        <div class="mt-1">
                                                            Next update: {{ $nextUpdate->diffForHumans() }}
                                                        </div>
                                                    @endif
                                                @else
                                                    <div class="mt-1 text-warning">
                                                        Never updated
                                                    </div>
                                                @endif
                                            </div>
                                        @endif
                                        
                                        @if($plugin['type'] === 'webhook' && $integration['account_id'])
                                            <div class="text-xs">
                                                <div class="text-base-content/70 mb-1">Webhook URL:</div>
                                                <div class="flex items-center space-x-2">
                                                    <code class="text-xs bg-base-300 px-2 py-1 rounded flex-1 truncate">
                                                        {{ route('webhook.handle', ['service' => $integration['service'], 'secret' => $integration['account_id']]) }}
                                                    </code>
                                                    <x-button 
                                                        icon="o-clipboard"
                                                        class="btn-ghost btn-xs"
                                                        wire:click="copyWebhookUrl('{{ route('webhook.handle', ['service' => $integration['service'], 'secret' => $integration['account_id']]) }}')"
                                                        title="Copy to clipboard"
                                                    />
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        
                        <div class="mt-4 pt-4 border-t border-base-300">
                            @if($plugin['type'] === 'oauth')
                                <x-button 
                                    label="{{ __('Add Instance') }}"
                                    icon="o-plus"
                                    link="{{ route('integrations.oauth', $plugin['identifier']) }}"
                                    class="btn-primary w-full"
                                />
                            @else
                                <x-button 
                                    label="{{ __('Add Instance') }}"
                                    icon="o-plus"
                                    wire:click="initializeIntegration('{{ $plugin['identifier'] }}')"
                                    class="btn-success w-full"
                                />
                            @endif
                        </div>
                    </x-card>
                @endforeach
            </div>
        </x-card>
    </div>
</div>
