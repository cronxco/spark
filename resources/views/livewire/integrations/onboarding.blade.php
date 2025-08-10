<x-layouts.app :title="'Onboarding'">
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <x-card :title="__('Configure ' . ($pluginName ?? ucfirst($group->service)))" shadow>
                <!-- Intro section to match configure page style -->
                <div class="mb-6 p-4 bg-base-200 rounded-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h4 class="text-lg font-medium">{{ __('Choose instances to set up') }}</h4>
                            <p class="text-sm text-base-content/70">{{ __('Select one or more instance types and set initial options. You can add more later.') }}</p>
                        </div>
                    </div>
                </div>

                <form method="POST" action="{{ route('integrations.storeInstances', ['group' => $group->id]) }}" class="space-y-6">
                    @csrf

                    <!-- Instance type selection -->
                    <div class="p-4 bg-base-200 rounded-lg">
                        <div class="mb-4">
                            <h4 class="text-lg font-medium">{{ __('Instance types') }}</h4>
                            <p class="text-sm text-base-content/70">{{ __('Tick the types you want to create now') }}</p>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            @foreach(($types ?? []) as $key => $meta)
                                <label class="flex items-center gap-3 p-3 rounded-lg bg-base-100">
                                    <input type="checkbox" name="types[]" value="{{ $key }}" class="checkbox">
                                    <div>
                                        <div class="font-medium">{{ $meta['label'] ?? ucfirst($key) }}</div>
                                        @if(!empty($meta['description']))
                                            <div class="text-xs text-base-content/70">{{ $meta['description'] }}</div>
                                        @endif
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <!-- Per-type configuration sections -->
                    @foreach(($types ?? []) as $typeKey => $meta)
                        <div class="p-4 bg-base-200 rounded-lg">
                            <div class="mb-4">
                                <h4 class="text-lg font-medium">{{ $meta['label'] ?? ucfirst($typeKey) }}</h4>
                                @if(!empty($meta['description']))
                                    <p class="text-sm text-base-content/70">{{ $meta['description'] }}</p>
                                @endif
                            </div>

                            <div class="space-y-3">
                                @foreach(($meta['schema'] ?? []) as $field => $config)
                                    <div>
                                        <div class="mb-1 text-sm font-medium">{{ $config['label'] ?? ucfirst($field) }}</div>
                                        @if(($config['type'] ?? 'string') === 'array' && isset($config['options']))
                                            @foreach($config['options'] as $value => $label)
                                                <label class="flex items-center gap-2">
                                                    <input type="checkbox" name="config[{{ $typeKey }}][{{ $field }}][]" value="{{ $value }}" class="checkbox">
                                                    <span>{{ $label }}</span>
                                                </label>
                                            @endforeach
                                        @elseif(($config['type'] ?? 'string') === 'array')
                                            <x-textarea name="config[{{ $typeKey }}][{{ $field }}]" rows="3" placeholder="{{ __('Comma-separated values') }}" />
                                        @elseif(($config['type'] ?? 'string') === 'integer')
                                            <x-input type="number" name="config[{{ $typeKey }}][{{ $field }}]" min="{{ $config['min'] ?? 1 }}" />
                                        @else
                                            <x-input name="config[{{ $typeKey }}][{{ $field }}]" />
                                        @endif
                                        @if(isset($config['description']))
                                            <div class="text-xs text-base-content/70 mt-1">{{ $config['description'] }}</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    <div class="flex justify-end space-x-3 pt-6 border-t border-base-300">
                        <x-button 
                            label="{{ __('Cancel') }}"
                            link="{{ route('integrations.index') }}"
                            class="btn-outline"
                        />
                        <x-button 
                            label="{{ __('Create Instances') }}"
                            type="submit"
                            class="btn-primary"
                        />
                    </div>
                </form>
            </x-card>
        </div>
    </div>
</x-layouts.app>


