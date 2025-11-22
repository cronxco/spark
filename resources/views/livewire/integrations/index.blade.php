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
    public string $search = '';
    public string $filter = 'all'; // all, connected, available

    protected $listeners = ['$refresh' => 'loadData'];

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
                'domain' => $pluginClass::getDomain(),
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

    public function confirmDeleteGroup(string $groupId): void
    {
        $this->dispatch('confirmDeleteGroup', groupId: $groupId);
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

    public function clearFilters(): void
    {
        $this->search = '';
        $this->filter = 'all';
    }

    public function getFilteredPlugins()
    {
        $plugins = collect($this->plugins);

        // Apply search filter
        if (!empty($this->search)) {
            $plugins = $plugins->filter(function ($plugin) {
                return str_contains(strtolower($plugin['name']), strtolower($this->search)) ||
                       str_contains(strtolower($plugin['description']), strtolower($this->search));
            });
        }

        // Apply status filter
        if ($this->filter === 'connected') {
            $connectedServices = collect($this->groups)->pluck('service')->toArray();
            $plugins = $plugins->filter(function ($plugin) use ($connectedServices) {
                return in_array($plugin['identifier'], $connectedServices);
            });
        } elseif ($this->filter === 'available') {
            $connectedServices = collect($this->groups)->pluck('service')->toArray();
            $plugins = $plugins->filter(function ($plugin) use ($connectedServices) {
                return !in_array($plugin['identifier'], $connectedServices);
            });
        }

        return $plugins;
    }
}; ?>

<div>
    <x-header title="Integrations" subtitle="Connect and manage your external services and data sources" separator />

    <!-- Desktop: Expanded filters -->
    <div class="hidden lg:block card bg-base-200 shadow mb-6">
        <div class="card-body">
            <div class="flex flex-row gap-4 items-end">
                <!-- Search (flex-1 to fill space) -->
                <div class="form-control flex-1">
                    <label class="label">
                        <span class="label-text">Search</span>
                    </label>
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search integrations..."
                        class="input input-bordered w-full"
                    />
                </div>

                <!-- Filter controls -->
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">Show</span>
                    </label>
                    <select wire:model.live="filter" class="select select-bordered">
                        <option value="all">All Integrations</option>
                        <option value="connected">Connected</option>
                        <option value="available">Available</option>
                    </select>
                </div>

                <!-- Clear filters button (aligned with inputs) -->
                @if (!empty($this->search) || $this->filter !== 'all')
                <div class="form-control">
                    <button wire:click="clearFilters" class="btn btn-outline btn-sm">
                        <x-icon name="fas.xmark" class="w-4 h-4" />
                        Clear
                    </button>
                </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Mobile: Collapsed by default -->
    <div class="lg:hidden mb-4">
        <x-collapse separator class="bg-base-200">
            <x-slot:heading>
                <div class="flex items-center gap-2">
                    <x-icon name="fas.filter" class="w-5 h-5" />
                    Filters
                    @if (!empty($this->search) || $this->filter !== 'all')
                        <x-badge value="Active" class="badge-success badge-xs" />
                    @endif
                </div>
            </x-slot:heading>
            <x-slot:content>
                <div class="flex flex-col gap-4">
                    <!-- Search -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">Search</span>
                        </label>
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="search"
                            placeholder="Search integrations..."
                            class="input input-bordered w-full"
                        />
                    </div>

                    <!-- Filter -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">Show</span>
                        </label>
                        <select wire:model.live="filter" class="select select-bordered w-full">
                            <option value="all">All Integrations</option>
                            <option value="connected">Connected</option>
                            <option value="available">Available</option>
                        </select>
                    </div>

                    @if (!empty($this->search) || $this->filter !== 'all')
                    <button wire:click="clearFilters" class="btn btn-outline btn-sm">
                        <x-icon name="fas.xmark" class="w-4 h-4" />
                        Clear Filters
                    </button>
                    @endif
                </div>
            </x-slot:content>
        </x-collapse>
    </div>

    @php
        $filteredPlugins = $this->getFilteredPlugins();
        $connectedServices = collect($this->groups)->pluck('service')->toArray();
        $connectedPlugins = $filteredPlugins->filter(fn($p) => in_array($p['identifier'], $connectedServices));
        // Show all plugins in available section, with badge showing count if connected
        $availablePlugins = $filteredPlugins;
        // Get instance counts per service
        $instanceCounts = collect($this->groups)->mapWithKeys(function($group) {
            return [$group['service'] => count($group['instances'])];
        });
    @endphp

    <!-- Connected Integration Groups Section -->
    @if ($connectedPlugins->count() > 0)
    <div class="mb-8">
        <h2 class="text-xl font-semibold mb-4">Connected Integrations</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 lg:gap-6">
            @foreach ($connectedPlugins as $plugin)
            @php
                $grouped = collect($groups)->where('service', $plugin['identifier'])->values();
            @endphp
            @foreach ($grouped as $group)
            <div class="card bg-base-200 shadow">
                <div class="card-body">
                    <!-- Plugin Header -->
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-lg bg-primary/10 flex items-center justify-center flex-shrink-0">
                            <x-icon :name="$plugin['icon']" class="w-5 h-5 text-primary" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="text-lg font-semibold truncate">
                                <a href="{{ route('plugins.show', $plugin['identifier']) }}" class="hover:text-primary transition-colors">
                                    {{ $plugin['name'] }}
                                </a>
                            </h3>
                            <p class="text-sm text-base-content/70 line-clamp-2">{{ $plugin['description'] }}</p>
                        </div>
                    </div>

                    <!-- Connected Instances -->
                    <div class="space-y-2">
                        @if (count($group['instances']) === 0)
                        <div class="text-center py-4 text-base-content/70">
                            <x-icon name="o-exclamation-circle" class="w-8 h-8 mx-auto mb-2 opacity-50" />
                            <p class="text-sm">No instances configured</p>
                        </div>
                        @else
                        @foreach ($group['instances'] as $integration)
                        <div class="border border-base-300 rounded-lg p-3 bg-base-100 group">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center gap-2 flex-1 min-w-0">
                                    <div x-data="{ editing: false, name: {{ json_encode($integration['name'] ?: $integration['service']) }} }" class="flex-1 min-w-0">
                                        <div x-show="!editing" class="flex items-center gap-1">
                                            <a href="{{ route('integrations.details', $integration['id']) }}" class="text-sm font-medium truncate hover:text-primary transition-colors">
                                                {{ $integration['name'] ?: $integration['service'] }}
                                            </a>
                                            <button
                                                @click="editing = true; $nextTick(() => $refs.nameInput.focus())"
                                                class="opacity-0 group-hover:opacity-100 hover:text-primary transition-opacity p-0.5"
                                                title="Edit name">
                                                <x-icon name="fas.pen" class="w-3 h-3" />
                                            </button>
                                        </div>
                                        <div x-show="editing" class="flex items-center gap-2">
                                            <input
                                                x-ref="nameInput"
                                                type="text"
                                                x-model="name"
                                                class="input input-xs input-bordered flex-1 min-w-0"
                                                @keydown.enter="$wire.updateIntegrationNameFromIndex('{{ $integration['id'] }}', name); editing = false"
                                                @keydown.escape="editing = false; name = {{ json_encode($integration['name'] ?: $integration['service']) }}"
                                                placeholder="Enter name" />
                                            <x-button
                                                icon="fas.check"
                                                class="btn-ghost btn-xs flex-shrink-0"
                                                @click="$wire.updateIntegrationNameFromIndex('{{ $integration['id'] }}', name); editing = false"
                                                title="Save" />
                                            <x-button
                                                icon="fas.xmark"
                                                class="btn-ghost btn-xs flex-shrink-0"
                                                @click="editing = false; name = {{ json_encode($integration['name'] ?: $integration['service']) }}"
                                                title="Cancel" />
                                        </div>
                                    </div>
                                </div>
                                <x-dropdown>
                                    <x-slot:trigger>
                                        <x-button icon="fas.ellipsis-vertical" class="btn-ghost btn-sm" />
                                    </x-slot:trigger>
                                    <x-menu-item title="{{ __('Configure') }}" link="{{ route('integrations.configure', $integration['id']) }}" icon="fas.gear" />
                                    <x-menu-item title="{{ __('View Details') }}" link="{{ route('integrations.details', $integration['id']) }}" icon="fas.eye" />
                                    <x-menu-item
                                        title="{{ __('Delete') }}"
                                        icon="fas.trash"
                                        wire:click="deleteIntegration('{{ $integration['id'] }}')"
                                        class="text-error" />
                                </x-dropdown>
                            </div>

                            @if ($plugin['type'] === 'oauth')
                            @php
                            $lastUpdate = $integration['last_successful_update_at'];
                            $needsUpdate = $integration['needs_update'];
                            @endphp

                            <div class="text-sm text-base-content/70 flex items-center gap-2">
                                @if ($lastUpdate)
                                    <div class="flex items-center gap-1">
                                        <x-icon name="fas.clock" class="w-3 h-3" />
                                        <span>{{ \Carbon\Carbon::parse($lastUpdate)->diffForHumans() }}</span>
                                    </div>
                                    @if ($needsUpdate)
                                    <x-badge value="Needs update" class="badge-warning badge-xs" />
                                    @endif
                                @else
                                    <div class="flex items-center gap-1 text-warning">
                                        <x-icon name="fas.triangle-exclamation" class="w-3 h-3" />
                                        <span>Never updated</span>
                                    </div>
                                @endif
                            </div>
                            @endif

                            @if ($plugin['type'] === 'webhook' && $integration['account_id'])
                            <div class="mt-2 text-sm">
                                <div class="flex items-center gap-2">
                                    <code class="text-xs bg-base-300 px-2 py-1 rounded flex-1 truncate">
                                        {{ route('webhook.handle', ['service' => $integration['service'], 'secret' => $integration['account_id']]) }}
                                    </code>
                                    <x-button
                                        icon="o-clipboard"
                                        class="btn-ghost btn-xs"
                                        wire:click="copyWebhookUrl('{{ route('webhook.handle', ['service' => $integration['service'], 'secret' => $integration['account_id']]) }}')"
                                        title="Copy to clipboard" />
                                </div>
                            </div>
                            @endif
                        </div>
                        @endforeach
                        @endif
                    </div>

                    <!-- Add Another Instance Button & Delete Group -->
                    <div class="pt-4 border-t border-base-300 space-y-2">
                        @if ($plugin['type'] === 'oauth')
                        @if ($plugin['identifier'] === 'gocardless')
                        <form method="POST" action="{{ route('integrations.initialize', ['service' => $plugin['identifier']]) }}" class="w-full">
                            @csrf
                            <button type="submit" class="btn btn-outline btn-sm w-full">
                                <x-icon name="fas.plus" class="w-4 h-4" />
                                {{ __('Add Another') }}
                            </button>
                        </form>
                        @else
                        <a href="{{ route('integrations.oauth', $plugin['identifier']) }}"
                            class="btn btn-outline btn-sm w-full">
                            <x-icon name="fas.plus" class="w-4 h-4" />
                            {{ __('Add Another') }}
                        </a>
                        @endif
                        @else
                        <form method="POST" action="{{ route('integrations.initialize', ['service' => $plugin['identifier']]) }}" class="w-full">
                            @csrf
                            <button type="submit" class="btn btn-outline btn-sm w-full">
                                <x-icon name="fas.plus" class="w-4 h-4" />
                                {{ __('Add Another') }}
                            </button>
                        </form>
                        @endif

                        <button
                            wire:click="confirmDeleteGroup('{{ $group['id'] }}')"
                            class="btn btn-outline btn-sm w-full text-error hover:bg-error hover:text-error-content">
                            <x-icon name="fas.trash" class="w-4 h-4" />
                            {{ __('Delete Integration') }}
                        </button>
                    </div>
                </div>
            </div>
            @endforeach
            @endforeach
        </div>
    </div>
    @endif

    <!-- Available Integrations Section -->
    @if ($availablePlugins->count() > 0)
    <div>
        <h2 class="text-xl font-semibold mb-4">Available Integrations</h2>

        @php
            $pluginsByDomain = $availablePlugins->groupBy('domain')->sortKeys();
            $domainLabels = [
                'health' => 'Health & Fitness',
                'money' => 'Finance',
                'media' => 'Media & Entertainment',
                'knowledge' => 'Knowledge & Productivity',
                'online' => 'Online & Social',
            ];
        @endphp

        @foreach ($pluginsByDomain as $domain => $domainPlugins)
        <div class="mb-8">
            <h3 class="text-lg font-medium text-base-content/70 mb-3">
                {{ $domainLabels[$domain] ?? ucfirst($domain) }}
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 lg:gap-6">
            @foreach ($domainPlugins as $plugin)
            <div class="card bg-base-200 shadow">
                <div class="card-body">
                    <!-- Plugin Header -->
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-lg bg-primary/10 flex items-center justify-center flex-shrink-0">
                            <x-icon :name="$plugin['icon']" class="w-5 h-5 text-primary" />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <h3 class="text-lg font-semibold truncate">
                                    <a href="{{ route('plugins.show', $plugin['identifier']) }}" class="hover:text-primary transition-colors">
                                        {{ $plugin['name'] }}
                                    </a>
                                </h3>
                                @if (isset($instanceCounts[$plugin['identifier']]) && $instanceCounts[$plugin['identifier']] > 0)
                                <x-badge value="{{ $instanceCounts[$plugin['identifier']] }}" class="badge-info badge-sm" />
                                @endif
                            </div>
                        </div>
                    </div>

                    <p class="text-sm text-base-content/70 mb-4">{{ $plugin['description'] }}</p>

                    <!-- Connect Button -->
                    <div>
                        @if ($plugin['type'] === 'oauth')
                        @if ($plugin['identifier'] === 'gocardless')
                        <form method="POST" action="{{ route('integrations.initialize', ['service' => $plugin['identifier']]) }}" class="w-full">
                            @csrf
                            <button type="submit" class="btn btn-outline btn-primary w-full">
                                <x-icon name="fas.plus" class="w-4 h-4" />
                                {{ __('Connect') }}
                            </button>
                        </form>
                        @else
                        <a href="{{ route('integrations.oauth', $plugin['identifier']) }}"
                            class="btn btn-outline btn-primary w-full">
                            <x-icon name="fas.plus" class="w-4 h-4" />
                            {{ __('Connect') }}
                        </a>
                        @endif
                        @else
                        <form method="POST" action="{{ route('integrations.initialize', ['service' => $plugin['identifier']]) }}" class="w-full">
                            @csrf
                            <button type="submit" class="btn btn-outline btn-primary w-full">
                                <x-icon name="fas.plus" class="w-4 h-4" />
                                {{ __('Connect') }}
                            </button>
                        </form>
                        @endif
                    </div>
                </div>
            </div>
            @endforeach
            </div>
        </div>
        @endforeach
    </div>
    @endif

    <!-- Empty state when no results after filtering -->
    @if ($filteredPlugins->count() === 0)
    <div class="text-center py-12">
        <x-icon name="fas.magnifying-glass" class="w-16 h-16 mx-auto text-base-content/70 mb-4" />
        <h3 class="text-lg font-medium text-base-content mb-2">No integrations found</h3>
        <p class="text-base-content/70 mb-6">
            Try adjusting your filters or search terms.
        </p>
        <x-button wire:click="clearFilters" class="btn-outline">
            <x-icon name="fas.xmark" class="w-4 h-4" />
            Clear Filters
        </x-button>
    </div>
    @endif

    <!-- Toast notifications -->
    <x-toast position="toast-top toast-end" />

    <!-- Delete Group Modal -->
    <livewire:actions.delete-integration-group />
</div>
