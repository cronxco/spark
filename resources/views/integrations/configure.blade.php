<x-layouts.app :title="__('Configure Integration')">
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <div class="mb-6">
                        <h3 class="text-lg font-medium">{{ $integration->name }}</h3>
                        <p class="text-gray-600 dark:text-gray-400">{{ $integration->service }}</p>
                    </div>
                    
                    <form method="POST" action="{{ route('integrations.configure.update', $integration) }}" class="space-y-6">
                        @csrf
                        
                        @foreach($schema as $field => $config)
                            <div>
                                <label for="{{ $field }}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ $config['label'] }}
                                    @if($config['required'] ?? false)
                                        <span class="text-red-500">*</span>
                                    @endif
                                </label>
                                
                                @if(isset($config['description']))
                                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $config['description'] }}</p>
                                @endif
                                
                                @if($config['type'] === 'array' && isset($config['options']))
                                    <div class="mt-2 space-y-2">
                                        @foreach($config['options'] as $value => $label)
                                            <div class="flex items-center">
                                                <input type="checkbox" 
                                                       id="{{ $field }}_{{ $value }}" 
                                                       name="{{ $field }}[]" 
                                                       value="{{ $value }}"
                                                       @if(in_array($value, old($field, $integration->configuration[$field] ?? []))) checked @endif
                                                       class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                                <label for="{{ $field }}_{{ $value }}" class="ml-2 text-sm text-gray-700 dark:text-gray-300">
                                                    {{ $label }}
                                                </label>
                                            </div>
                                        @endforeach
                                    </div>
                                @elseif($config['type'] === 'array')
                                    <textarea id="{{ $field }}" 
                                              name="{{ $field }}" 
                                              rows="3"
                                              placeholder="Enter values separated by commas"
                                              class="mt-1 block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">{{ old($field, is_array($integration->configuration[$field] ?? null) ? implode(',', $integration->configuration[$field]) : '') }}</textarea>
                                @elseif($config['type'] === 'string')
                                    <input type="text" 
                                           id="{{ $field }}" 
                                           name="{{ $field }}" 
                                           value="{{ old($field, $integration->configuration[$field] ?? '') }}"
                                           class="mt-1 block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                @elseif($config['type'] === 'integer')
                                    <input type="number" 
                                           id="{{ $field }}" 
                                           name="{{ $field }}" 
                                           value="{{ old($field, $integration->configuration[$field] ?? $config['default'] ?? '') }}"
                                           min="{{ $config['min'] ?? 1 }}"
                                           class="mt-1 block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                @endif
                                
                                @error($field)
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        @endforeach
                        
                        <div class="flex justify-end space-x-3">
                            <a href="{{ route('integrations.index') }}" 
                               class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 shadow-sm text-sm font-medium rounded-md text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                Cancel
                            </a>
                            
                            <button type="submit" 
                                    class="inline-flex items-center px-4 py-2 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest bg-blue-600 hover:bg-blue-700 focus:bg-blue-700 active:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition ease-in-out duration-150">
                                Save Configuration
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app> 