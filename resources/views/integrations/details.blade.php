<x-layouts.app>
    <x-slot:title>{{ $integration->name }} Details</x-slot:title>

    <div class="flex flex-col gap-6">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-base-content">{{ $integration->name ?: $integration->service }}</h1>
                <p class="text-base-content/70">{{ $integration->service }} integration</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('updates.index') }}" class="btn btn-outline">
                    <x-icon name="o-arrow-left" class="w-4 h-4" />
                    Back to Updates
                </a>
            </div>
        </div>

        <!-- Integration Info -->
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <div class="flex items-center gap-4">
                    <div class="w-16 h-16 rounded-lg flex items-center justify-center text-2xl bg-primary text-primary-content">
                        <x-icon name="o-cog" class="w-8 h-8" />
                    </div>
                    <div class="flex-1">
                        <div class="flex items-center gap-4">
                            <span class="badge badge-primary">{{ $integration->instance_type }}</span>
                            <span class="badge badge-outline">{{ $integration->account_id }}</span>
                            @if ($integration->update_frequency_minutes)
                                <span class="badge badge-secondary">Updates every {{ $integration->update_frequency_minutes }} minutes</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @php
            $pluginClass = \App\Integrations\PluginRegistry::getPlugin($integration->service);
        @endphp

        @if ($pluginClass)
            <!-- Action Types Section -->
            <div class="card bg-base-100 shadow-sm">
                <div class="card-body">
                    <h2 class="card-title">Action Types</h2>
                    @if (count($pluginClass::getActionTypes()) > 0)
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            @foreach ($pluginClass::getActionTypes() as $key => $action)
                                @php
                                    $count = \App\Models\Event::where('integration_id', $integration->id)
                                        ->where('action', $key)
                                        ->count();

                                    $recent = \App\Models\Event::where('integration_id', $integration->id)
                                        ->where('action', $key)
                                        ->orderBy('created_at', 'desc')
                                        ->limit(5)
                                        ->get();

                                    $newest = $recent->first();
                                @endphp

                                <div class="card bg-base-200 shadow-sm">
                                    <div class="card-body">
                                        <div class="flex items-center gap-3 mb-4">
                                            <div class="w-10 h-10 rounded-lg flex items-center justify-center bg-primary text-primary-content">
                                                <x-icon name="{{ $action['icon'] }}" class="w-5 h-5" />
                                            </div>
                                            <div>
                                                <h3 class="font-medium text-base-content">{{ $action['display_name'] }}</h3>
                                                <p class="text-sm text-base-content/70">{{ $action['description'] }}</p>
                                            </div>
                                        </div>

                                        <div class="stats stats-horizontal shadow mb-4">
                                            <div class="stat">
                                                <div class="stat-title text-xs">Total</div>
                                                <div class="stat-value text-2xl">{{ $count }}</div>
                                            </div>
                                            @if ($newest)
                                                <div class="stat">
                                                    <div class="stat-title text-xs">Newest</div>
                                                    <div class="stat-value text-sm">{{ $newest->created_at->diffForHumans() }}</div>
                                                </div>
                                            @endif
                                        </div>

                                        @if ($recent->count() > 0)
                                            <div class="space-y-2">
                                                <h4 class="font-medium text-sm text-base-content">Recent {{ $action['display_name'] }}:</h4>
                                                <div class="overflow-x-auto">
                                                    <table class="table table-xs">
                                                        <tbody>
                                                            @foreach ($recent as $event)
                                                                <tr>
                                                                    <td class="text-sm">{{ $event->title ?: $event->action }}</td>
                                                                    <td class="text-sm text-base-content/70">{{ $event->created_at->format('M j') }}</td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        @else
                                            <div class="text-center py-4">
                                                <p class="text-sm text-base-content/70">No {{ $action['display_name'] }} recorded yet</p>
                                            </div>
                                        @endif

                                        @if ($action['value_unit'])
                                            <div class="mt-4 flex gap-2">
                                                <span class="badge badge-outline">Unit: {{ $action['value_unit'] }}</span>
                                                @if ($action['display_with_object'])
                                                    <span class="badge badge-outline">With Object</span>
                                                @else
                                                    <span class="badge badge-outline">Value Only</span>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-8">
                            <x-icon name="o-exclamation-triangle" class="w-16 h-16 mx-auto text-base-content/70 mb-4" />
                            <h3 class="text-lg font-medium text-base-content mb-2">No Action Types Defined</h3>
                            <p class="text-base-content/70">This plugin doesn't define any action types.</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Object Types Section -->
            <div class="card bg-base-100 shadow-sm">
                <div class="card-body">
                    <h2 class="card-title">Object Types</h2>
                    @if (count($pluginClass::getObjectTypes()) > 0)
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            @foreach ($pluginClass::getObjectTypes() as $key => $object)
                                @php
                                    $count = \App\Models\EventObject::where('concept', $key)
                                        ->where(function($query) use ($integration) {
                                            $query->whereHas('actorEvents', function($q) use ($integration) {
                                                $q->where('integration_id', $integration->id);
                                            })->orWhereHas('targetEvents', function($q) use ($integration) {
                                                $q->where('integration_id', $integration->id);
                                            });
                                        })
                                        ->count();

                                    $recent = \App\Models\EventObject::where('concept', $key)
                                        ->where(function($query) use ($integration) {
                                            $query->whereHas('actorEvents', function($q) use ($integration) {
                                                $q->where('integration_id', $integration->id);
                                            })->orWhereHas('targetEvents', function($q) use ($integration) {
                                                $q->where('integration_id', $integration->id);
                                            });
                                        })
                                        ->with(['actorEvents', 'targetEvents'])
                                        ->orderBy('created_at', 'desc')
                                        ->limit(5)
                                        ->get();

                                    $newest = $recent->first();
                                @endphp

                                <div class="card bg-base-200 shadow-sm">
                                    <div class="card-body">
                                        <div class="flex items-center gap-3 mb-4">
                                            <div class="w-10 h-10 rounded-lg flex items-center justify-center bg-primary text-primary-content">
                                                <x-icon name="{{ $object['icon'] }}" class="w-5 h-5" />
                                            </div>
                                            <div>
                                                <h3 class="font-medium text-base-content">{{ $object['display_name'] }}</h3>
                                                <p class="text-sm text-base-content/70">{{ $object['description'] }}</p>
                                            </div>
                                        </div>

                                        <div class="stats stats-horizontal shadow mb-4">
                                            <div class="stat">
                                                <div class="stat-title text-xs">Total</div>
                                                <div class="stat-value text-2xl">{{ $count }}</div>
                                            </div>
                                            @if ($newest)
                                                <div class="stat">
                                                    <div class="stat-title text-xs">Newest</div>
                                                    <div class="stat-value text-sm">{{ $newest->created_at->diffForHumans() }}</div>
                                                </div>
                                            @endif
                                        </div>

                                        @if ($recent->count() > 0)
                                            <div class="space-y-2">
                                                <h4 class="font-medium text-sm text-base-content">Recent {{ $object['display_name'] }}:</h4>
                                                <div class="overflow-x-auto">
                                                    <table class="table table-xs">
                                                        <tbody>
                                                            @foreach ($recent as $objectItem)
                                                                <tr>
                                                                    <td class="text-sm">{{ $objectItem->title ?: $objectItem->concept }}</td>
                                                                    <td class="text-sm text-base-content/70">{{ $objectItem->created_at->format('M j') }}</td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        @else
                                            <div class="text-center py-4">
                                                <p class="text-sm text-base-content/70">No {{ $object['display_name'] }} recorded yet</p>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-8">
                            <x-icon name="o-exclamation-triangle" class="w-16 h-16 mx-auto text-base-content/70 mb-4" />
                            <h3 class="text-lg font-medium text-base-content mb-2">No Object Types Defined</h3>
                            <p class="text-base-content/70">This plugin doesn't define any object types.</p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Block Types Section -->
            <div class="card bg-base-100 shadow-sm">
                <div class="card-body">
                    <h2 class="card-title">Block Types</h2>
                    @if (count($pluginClass::getBlockTypes()) > 0)
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            @foreach ($pluginClass::getBlockTypes() as $key => $block)
                                @php
                                    $count = \App\Models\Block::where('block_type', $key)
                                        ->whereHas('event', function($query) use ($integration) {
                                            $query->where('integration_id', $integration->id);
                                        })
                                        ->count();

                                    $recent = \App\Models\Block::where('block_type', $key)
                                        ->whereHas('event', function($query) use ($integration) {
                                            $query->where('integration_id', $integration->id);
                                        })
                                        ->with('event')
                                        ->orderBy('created_at', 'desc')
                                        ->limit(5)
                                        ->get();

                                    $newest = $recent->first();
                                @endphp

                                <div class="card bg-base-200 shadow-sm">
                                    <div class="card-body">
                                        <div class="flex items-center gap-3 mb-4">
                                            <div class="w-10 h-10 rounded-lg flex items-center justify-center bg-primary text-primary-content">
                                                <x-icon name="{{ $block['icon'] }}" class="w-5 h-5" />
                                            </div>
                                            <div>
                                                <h3 class="font-medium text-base-content">{{ $block['display_name'] }}</h3>
                                                <p class="text-sm text-base-content/70">{{ $block['description'] }}</p>
                                            </div>
                                        </div>

                                        <div class="stats stats-horizontal shadow mb-4">
                                            <div class="stat">
                                                <div class="stat-title text-xs">Total</div>
                                                <div class="stat-value text-2xl">{{ $count }}</div>
                                            </div>
                                            @if ($newest)
                                                <div class="stat">
                                                    <div class="stat-title text-xs">Newest</div>
                                                    <div class="stat-value text-sm">{{ $newest->created_at->diffForHumans() }}</div>
                                                </div>
                                            @endif
                                        </div>

                                        @if ($recent->count() > 0)
                                            <div class="space-y-2">
                                                <h4 class="font-medium text-sm text-base-content">Recent {{ $block['display_name'] }}:</h4>
                                                <div class="overflow-x-auto">
                                                    <table class="table table-xs">
                                                        <tbody>
                                                            @foreach ($recent as $blockItem)
                                                                <tr>
                                                                    <td class="text-sm">{{ $blockItem->title ?: $blockItem->block_type }}</td>
                                                                    <td class="text-sm text-base-content/70">{{ $blockItem->created_at->format('M j') }}</td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        @else
                                            <div class="text-center py-4">
                                                <p class="text-sm text-base-content/70">No {{ $block['display_name'] }} recorded yet</p>
                                            </div>
                                        @endif

                                        @if ($block['value_unit'])
                                            <div class="mt-4 flex gap-2">
                                                <span class="badge badge-outline">Unit: {{ $block['value_unit'] }}</span>
                                                @if ($block['display_with_object'])
                                                    <span class="badge badge-outline">With Object</span>
                                                @else
                                                    <span class="badge badge-outline">Value Only</span>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-8">
                            <x-icon name="o-exclamation-triangle" class="w-16 h-16 mx-auto text-base-content/70 mb-4" />
                            <h3 class="text-lg font-medium text-base-content mb-2">No Block Types Defined</h3>
                            <p class="text-base-content/70">This plugin doesn't define any block types.</p>
                        </div>
                    @endif
                </div>
            </div>
        @else
            <div class="card bg-base-100 shadow-sm">
                <div class="card-body">
                    <div class="text-center py-8">
                        <x-icon name="o-exclamation-triangle" class="w-16 h-16 mx-auto text-base-content/70 mb-4" />
                        <h3 class="text-lg font-medium text-base-content mb-2">Plugin Configuration Not Found</h3>
                        <p class="text-base-content/70">Plugin configuration not found for this integration.</p>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-layouts.app>
