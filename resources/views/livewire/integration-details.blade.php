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
                            @if ($integration->getUpdateFrequencyMinutes())
    <span class="badge badge-secondary">Updates every {{ $integration->getUpdateFrequencyMinutes() }} minutes</span>
@endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if ($this->getPluginClass())
            <!-- Action Types Section -->
            <div class="card bg-base-100 shadow-sm">
                <div class="card-body">
                    <h2 class="card-title">Action Types</h2>
                    @if ($this->getActionTypes()->count() > 0)
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            @foreach ($this->getActionTypes() as $actionType)
                                <div class="card bg-base-200 shadow-sm">
                                    <div class="card-body">
                                        <div class="flex items-center gap-3 mb-4">
                                            <div class="w-10 h-10 rounded-lg flex items-center justify-center bg-primary text-primary-content">
                                                <x-icon name="{{ $actionType['action']['icon'] }}" class="w-5 h-5" />
                                            </div>
                                            <div>
                                                <h3 class="font-medium text-base-content">{{ $actionType['action']['display_name'] }}</h3>
                                                <p class="text-sm text-base-content/70">{{ $actionType['action']['description'] }}</p>
                                            </div>
                                        </div>

                                        <div class="stats stats-horizontal shadow mb-4">
                                            <div class="stat">
                                                <div class="stat-title text-xs">Total</div>
                                                <div class="stat-value text-2xl">{{ $actionType['count'] }}</div>
                                            </div>
                                            @if ($actionType['newest'])
                                                <div class="stat">
                                                    <div class="stat-title text-xs">Newest</div>
                                                    <div class="stat-value text-sm">{{ $actionType['newest']->created_at->diffForHumans() }}</div>
                                                </div>
                                            @endif
                                        </div>

                                        @if ($actionType['recent']->count() > 0)
                                            <div class="space-y-2">
                                                <h4 class="font-medium text-sm text-base-content">Recent {{ $actionType['action']['display_name'] }}:</h4>
                                                <div class="overflow-x-auto">
                                                    <table class="table table-xs">
                                                        <tbody>
                                                            @foreach ($actionType['recent'] as $event)
                                                                <tr>
                                                                    <td class="text-sm">{{ $event->target?->title ?: $event->action }}</td>
                                                                    <td class="text-sm text-base-content/70">
                                                                        <a href="{{ route('events.show', $event->id) }}" class="link link-hover">
                                                                            {{ $event->created_at->diffForHumans() }}
                                                                        </a>
                                                                    </td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        @else
                                            <div class="text-center py-4">
                                                <p class="text-sm text-base-content/70">No {{ $actionType['action']['display_name'] }} recorded yet</p>
                                            </div>
                                        @endif

                                        @if ($actionType['action']['value_unit'])
                                            <div class="mt-4 flex gap-2">
                                                <span class="badge badge-outline">Unit: {{ $actionType['action']['value_unit'] }}</span>
                                                @if ($actionType['action']['display_with_object'])
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
                    @if ($this->getObjectTypes()->count() > 0)
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            @foreach ($this->getObjectTypes() as $objectType)
                                <div class="card bg-base-200 shadow-sm">
                                    <div class="card-body">
                                        <div class="flex items-center gap-3 mb-4">
                                            <div class="w-10 h-10 rounded-lg flex items-center justify-center bg-primary text-primary-content">
                                                <x-icon name="{{ $objectType['object']['icon'] }}" class="w-5 h-5" />
                                            </div>
                                            <div>
                                                <h3 class="font-medium text-base-content">{{ $objectType['object']['display_name'] }}</h3>
                                                <p class="text-sm text-base-content/70">{{ $objectType['object']['description'] }}</p>
                                            </div>
                                        </div>

                                        <div class="stats stats-horizontal shadow mb-4">
                                            <div class="stat">
                                                <div class="stat-title text-xs">Total</div>
                                                <div class="stat-value text-2xl">{{ $objectType['count'] }}</div>
                                            </div>
                                            @if ($objectType['newest'])
                                                <div class="stat">
                                                    <div class="stat-title text-xs">Newest</div>
                                                    <div class="stat-value text-sm">{{ $objectType['newest']->created_at->diffForHumans() }}</div>
                                                </div>
                                            @endif
                                        </div>

                                        @if ($objectType['recent']->count() > 0)
                                            <div class="space-y-2">
                                                <h4 class="font-medium text-sm text-base-content">Recent {{ $objectType['object']['display_name'] }}:</h4>
                                                <div class="overflow-x-auto">
                                                    <table class="table table-xs">
                                                        <tbody>
                                                            @foreach ($objectType['recent'] as $objectItem)
                                                                <tr>
                                                                    <td class="text-sm">{{ $objectItem->title ?: $objectItem->concept }}</td>
                                                                    <td class="text-sm text-base-content/70">
                                                                        <a href="{{ route('objects.show', $objectItem->id) }}" class="link link-hover">
                                                                            {{ $objectItem->created_at->diffForHumans() }}
                                                                        </a>
                                                                    </td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        @else
                                            <div class="text-center py-4">
                                                <p class="text-sm text-base-content/70">No {{ $objectType['object']['display_name'] }} recorded yet</p>
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
                    @if ($this->getBlockTypes()->count() > 0)
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            @foreach ($this->getBlockTypes() as $blockType)
                                <div class="card bg-base-200 shadow-sm">
                                    <div class="card-body">
                                        <div class="flex items-center gap-3 mb-4">
                                            <div class="w-10 h-10 rounded-lg flex items-center justify-center bg-primary text-primary-content">
                                                <x-icon name="{{ $blockType['block']['icon'] }}" class="w-5 h-5" />
                                            </div>
                                            <div>
                                                <h3 class="font-medium text-base-content">{{ $blockType['block']['display_name'] }}</h3>
                                                <p class="text-sm text-base-content/70">{{ $blockType['block']['description'] }}</p>
                                            </div>
                                        </div>

                                        <div class="stats stats-horizontal shadow mb-4">
                                            <div class="stat">
                                                <div class="stat-title text-xs">Total</div>
                                                <div class="stat-value text-2xl">{{ $blockType['count'] }}</div>
                                            </div>
                                            @if ($blockType['newest'])
                                                <div class="stat">
                                                    <div class="stat-title text-xs">Newest</div>
                                                    <div class="stat-value text-sm">{{ $blockType['newest']->created_at->diffForHumans() }}</div>
                                                </div>
                                            @endif
                                        </div>

                                        @if ($blockType['recent']->count() > 0)
                                            <div class="space-y-2">
                                                <h4 class="font-medium text-sm text-base-content">Recent {{ $blockType['block']['display_name'] }}:</h4>
                                                <div class="overflow-x-auto">
                                                    <table class="table table-xs">
                                                        <tbody>
                                                            @foreach ($blockType['recent'] as $blockItem)
                                                                <tr>
                                                                    <td class="text-sm">{{ $blockItem->title ?: ($blockItem->block_type ?: 'Block') }}</td>
                                                                    <td class="text-sm text-base-content/70">
                                                                        <a href="{{ route('blocks.show', $blockItem->id) }}" class="link link-hover">
                                                                            {{ $blockItem->created_at->diffForHumans() }}
                                                                        </a>
                                                                    </td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        @else
                                            <div class="text-center py-4">
                                                <p class="text-sm text-base-content/70">No {{ $blockType['block']['display_name'] }} recorded yet</p>
                                            </div>
                                        @endif

                                        @if ($blockType['block']['value_unit'])
                                            <div class="mt-4 flex gap-2">
                                                <span class="badge badge-outline">Unit: {{ $blockType['block']['value_unit'] }}</span>
                                                @if ($blockType['block']['display_with_object'])
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
