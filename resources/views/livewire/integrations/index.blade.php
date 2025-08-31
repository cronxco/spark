<?php
use App\Integrations\PluginRegistry;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public array $plugins = [];
    public array $groups = [];
    // Back-compat for tests expecting this property
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
                'icon' => $pluginClass::getIcon(),
            ];
        })->toArray();

        $user = Auth::user();
        $userGroups = IntegrationGroup::query()
            ->where('user_id', $user->id)
            ->with(['integrations'])
            ->get();
        $this->groups = $userGroups->map(function ($group) {
            $pluginClass = PluginRegistry::getPlugin($group->service);
            return [
                'id' => (string) $group->id,
                'service' => $group->service,
                'service_name' => $pluginClass ? $pluginClass::getDisplayName() : ucfirst($group->service),
                'account_id' => $group->account_id,
                'type' => $pluginClass ? $pluginClass::getServiceType() : 'oauth',
                'instances' => $group->integrations->map(function ($integration) {
                    return [
                        'id' => (string) $integration->id,
                        'name' => $integration->name ?: $integration->service,
                        'instance_type' => $integration->instance_type,
                        'update_frequency_minutes' => $integration->getUpdateFrequencyMinutes(),
                        'last_successful_update_at' => $integration->last_successful_update_at ? $integration->last_successful_update_at->toISOString() : null,
                        'needs_update' => $integration->needsUpdate(),
                        'next_update_time' => $integration->getNextUpdateTime() ? $integration->getNextUpdateTime()->toISOString() : null,
                        'service' => $integration->service,
                        'account_id' => $integration->account_id,
                    ];
                })->toArray(),
            ];
        })->toArray();
        // Maintain legacy structure for tests
        $this->integrationsByService = collect($this->groups)
            ->flatMap(function ($g) {
                return collect($g['instances'])->map(function ($i) use ($g) {
                    return array_merge($i, ['service' => $g['service']]);
                });
            })
            ->groupBy('service')
            ->map(fn($col) => $col->values()->toArray())
            ->toArray();
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

    public function deleteIntegration($integrationId): void
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

        try {
            $result = $integration->delete();

            if ($result) {
                $this->success('Integration deleted successfully!');
                $this->loadData();
            } else {
                $this->error('Failed to delete integration. Delete returned false.');
            }
        } catch (\Exception $e) {
            $this->error('Failed to delete integration. Please try again.');
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

<div>
    <div class="flex flex-col gap-6">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-base-content">Integrations</h1>
                <p class="text-base-content/70">Connect and manage your external services and data sources</p>
            </div>
        </div>

        <!-- Available Integrations -->
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <h2 class="text-xl font-semibold mb-4">Available Integrations</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach ($plugins as $plugin)
                        <div class="card bg-base-200 shadow-sm">
                            <div class="card-body">
                                <div class="flex items-start justify-between mb-4">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-10 h-10 rounded-lg bg-base-300 flex items-center justify-center">
                                            <x-icon :name="$plugin['icon']" class="w-5 h-5 text-base-content" />
                                        </div>
                                        <div>
                                            <div class="flex items-center justify-between">
                                                <h4 class="text-lg font-semibold">
                                                    <a href="{{ route('plugins.show', $plugin['identifier']) }}" class="hover:text-primary transition-colors">
                                                        {{ $plugin['name'] }}
                                                    </a>
                                                </h4>
                                                <x-badge
                                                    :value="ucfirst($plugin['type'])"
                                                    :class="$plugin['type'] === 'oauth' ? 'badge-primary' : 'badge-success'"
                                                />
                                            </div>
                                            <p class="text-sm text-base-content/70">{{ $plugin['description'] }}</p>
                                        </div>
                                    </div>
                                </div>

                                @php
                                    $grouped = collect($groups)->where('service', $plugin['identifier'])->values();
                                @endphp

                                @if ($grouped->count() > 0)
                                    <div class="space-y-3">
                                        @foreach ($grouped as $group)
                                            <div class="border border-base-300 rounded-lg p-3 bg-base-100">
                                                <div class="mb-2 text-xs text-base-content/70">
                                                    Account: {{ $group['account_id'] ?? '—' }}
                                                </div>
                                                @foreach ($group['instances'] as $integration)
                                                    <div class="border border-base-300 rounded-lg p-3 bg-base-200 mb-2">
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
                                                                    title="{{ __('Delete') }}"
                                                                    wire:click="deleteIntegration('{{ $integration['id'] }}')"
                                                                    class="text-error"
                                                                />
                                                            </x-dropdown>
                                                        </div>

                                                        @if ($plugin['type'] === 'oauth')
                                                            @php
                                                                $frequency = $integration['update_frequency_minutes'] ?? 15;
                                                                $lastUpdate = $integration['last_successful_update_at'];
                                                                $nextUpdate = $integration['next_update_time'];
                                                                $needsUpdate = $integration['needs_update'];
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

                                                                @if ($lastUpdate)
                                                                    <div class="mt-1">
                                                                        Last update: {{ \Carbon\Carbon::parse($lastUpdate)->diffForHumans() }}
                                                                    </div>
                                                                    @if ($nextUpdate)
                                                                        <div class="mt-1">
                                                                            Next update: {{ \Carbon\Carbon::parse($nextUpdate)->diffForHumans() }}
                                                                        </div>
                                                                    @endif
                                                                @else
                                                                    <div class="mt-1 text-warning">
                                                                        Never updated
                                                                    </div>
                                                                @endif
                                                            </div>
                                                        @endif

                                                        @if ($plugin['type'] === 'webhook' && $integration['account_id'])
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
                                        @endforeach
                                    </div>
                                @endif

                                <div class="mt-4 pt-4 border-t border-base-300">
                                    @if ($plugin['type'] === 'oauth')
                                        @if ($plugin['identifier'] === 'gocardless')
                                            <form method="POST" action="{{ route('integrations.initialize', ['service' => $plugin['identifier']]) }}" class="w-full">
                                                @csrf
                                                <button type="submit" class="btn btn-outline w-full">
                                                    <x-icon name="o-plus" class="w-4 h-4 mr-2" />
                                                    {{ __('Add Instance') }}
                                                </button>
                                            </form>
                                        @else
                                            <a href="{{ route('integrations.oauth', $plugin['identifier']) }}"
                                               class="btn btn-outline w-full">
                                                <x-icon name="o-plus" class="w-4 h-4 mr-2" />
                                                {{ __('Add Instance') }}
                                            </a>
                                        @endif
                                    @else
                                        <form method="POST" action="{{ route('integrations.initialize', ['service' => $plugin['identifier']]) }}" class="w-full">
                                            @csrf
                                            <button type="submit" class="btn btn-outline w-full">
                                                <x-icon name="o-plus" class="w-4 h-4 mr-2" />
                                                {{ __('Add Instance') }}
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <!-- Toast notifications -->
    <x-toast position="toast-top toast-end" />
</div>
