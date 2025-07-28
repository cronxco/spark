<x-layouts.app :title="__('Integrations')">
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-medium">{{ __('Available Integrations') }}</h3>
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                            {{ __('Add Integration') }}
                        </button>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        @foreach($plugins as $plugin)
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                            @if($plugin['identifier'] === 'github')
                                                <svg class="w-4 h-4 text-gray-600 dark:text-gray-400" fill="currentColor" viewBox="0 0 24 24">
                                                    <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/>
                                                </svg>
                                            @elseif($plugin['identifier'] === 'slack')
                                                <svg class="w-4 h-4 text-gray-600 dark:text-gray-400" fill="currentColor" viewBox="0 0 24 24">
                                                    <path d="M6.194 14.644c0 1.16-.943 2.107-2.107 2.107-1.164 0-2.107-.947-2.107-2.107 0-1.16.943-2.106 2.107-2.106 1.164 0 2.107.946 2.107 2.106zm5.882-2.107c-1.164 0-2.107.946-2.107 2.106 0 1.16.943 2.107 2.107 2.107 1.164 0 2.107-.947 2.107-2.107 0-1.16-.943-2.106-2.107-2.106zm2.107-5.882c0-1.164-.943-2.107-2.107-2.107-1.164 0-2.107.943-2.107 2.107 0 1.164.943 2.107 2.107 2.107 1.164 0 2.107-.943 2.107-2.107zm2.106 5.882c0-1.164-.943-2.107-2.107-2.107-1.164 0-2.107.943-2.107 2.107 0 1.164.943 2.107 2.107 2.107 1.164 0 2.107-.943 2.107-2.107zm5.882-2.107c-1.164 0-2.107.946-2.107 2.106 0 1.16.943 2.107 2.107 2.107 1.164 0 2.107-.947 2.107-2.107 0-1.16-.943-2.106-2.107-2.106z"/>
                                                </svg>
                                            @else
                                                <svg class="w-4 h-4 text-gray-600 dark:text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M3 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"></path>
                                                </svg>
                                            @endif
                                        </div>
                                        <div>
                                            <h4 class="text-lg font-semibold">{{ $plugin['name'] }}</h4>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $plugin['description'] }}</p>
                                        </div>
                                    </div>
                                    <span class="px-2 py-1 text-xs font-medium rounded-full {{ $plugin['type'] === 'oauth' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' : 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' }}">
                                        {{ ucfirst($plugin['type']) }}
                                    </span>
                                </div>
                                
                                @php
                                    $userIntegrations = $integrationsByService->get($plugin['identifier'], collect());
                                @endphp
                                
                                @if($userIntegrations->count() > 0)
                                    <div class="space-y-3">
                                        <div class="flex items-center text-sm text-gray-500 dark:text-gray-400">
                                            <svg class="w-4 h-4 mr-2 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                            </svg>
                                            {{ $userIntegrations->count() }} instance{{ $userIntegrations->count() > 1 ? 's' : '' }} connected
                                        </div>
                                        
                                        @foreach($userIntegrations as $integration)
                                            <div class="border border-gray-200 dark:border-gray-600 rounded-lg p-3 bg-gray-50 dark:bg-gray-700">
                                                <div class="flex items-center justify-between mb-2">
                                                    <div class="flex items-center space-x-2">
                                                        <svg class="w-3 h-3 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fill-rule="evenodd" d="M12.586 4.586a2 2 0 112.828 2.828l-3 3a2 2 0 01-2.828 0 1 1 0 00-1.414 1.414 4 4 0 005.656 0l3-3a4 4 0 00-5.656-5.656l-1.5 1.5a1 1 0 101.414 1.414l1.5-1.5zm-5 5a2 2 0 012.828 0 1 1 0 102.828-2.828 4 4 0 00-5.656 0l-3 3a4 4 0 105.656 5.656l1.5-1.5a1 1 0 10-1.414-1.414l-1.5 1.5a2 2 0 11-2.828-2.828l3-3z" clip-rule="evenodd"></path>
                                                        </svg>
                                                        <span class="text-sm font-medium">{{ $integration->name ?: $integration->service }}</span>
                                                    </div>
                                                    <div class="relative" x-data="{ open: false }">
                                                        <button type="button" @click="open = !open" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                                                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                                <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"></path>
                                                            </svg>
                                                        </button>
                                                        <div x-show="open" @click.away="open = false" class="absolute right-0 mt-2 w-48 bg-white dark:bg-gray-800 rounded-md shadow-lg py-1 z-10">
                                                            <a href="{{ route('integrations.configure', $integration) }}" class="block px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                                                                {{ __('Configure') }}
                                                            </a>
                                                            <form method="POST" action="{{ route('integrations.disconnect', $integration) }}" class="block">
                                                                @csrf
                                                                @method('DELETE')
                                                                <button type="submit" class="block w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-100 dark:hover:bg-gray-700">
                                                                    {{ __('Disconnect') }}
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                @if($integration->account_id)
                                                    <div class="text-xs text-gray-500 dark:text-gray-400 mb-2">
                                                        Account: {{ $integration->account_id }}
                                                    </div>
                                                @endif
                                                
                                                @if($plugin['type'] === 'webhook' && $integration->account_id)
                                                    <div class="text-xs" x-data="{ 
                                                        webhookUrl: '{{ route('webhook.handle', ['service' => $integration->service, 'secret' => $integration->account_id]) }}',
                                                        copied: false,
                                                        copyToClipboard() {
                                                            navigator.clipboard.writeText(this.webhookUrl).then(() => {
                                                                this.copied = true;
                                                                setTimeout(() => {
                                                                    this.copied = false;
                                                                }, 2000);
                                                            }).catch(err => {
                                                                console.error('Failed to copy: ', err);
                                                            });
                                                        }
                                                    }">
                                                        <div class="text-gray-500 dark:text-gray-400 mb-1">Webhook URL:</div>
                                                        <div class="flex items-center space-x-2">
                                                            <code class="text-xs bg-gray-100 dark:bg-gray-600 px-2 py-1 rounded flex-1 truncate">
                                                                {{ route('webhook.handle', ['service' => $integration->service, 'secret' => $integration->account_id]) }}
                                                            </code>
                                                            <button type="button" 
                                                                    @click="copyToClipboard()"
                                                                    class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors duration-200"
                                                                    :class="{ 'text-green-500': copied }"
                                                                    :title="copied ? 'Copied!' : 'Copy to clipboard'">
                                                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                                                    <path d="M8 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z"></path>
                                                                    <path d="M6 3a2 2 0 00-2 2v11a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2 3 3 0 01-3 3H9a3 3 0 01-3-3z"></path>
                                                                </svg>
                                                            </button>
                                                        </div>
                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                                
                                <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-600">
                                    @if($plugin['type'] === 'oauth')
                                        <a href="{{ route('integrations.oauth', $plugin['identifier']) }}" 
                                           class="inline-flex items-center justify-center w-full px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                            </svg>
                                            {{ __('Add Instance') }}
                                        </a>
                                    @else
                                        <form method="POST" action="{{ route('integrations.initialize', $plugin['identifier']) }}" class="w-full">
                                            @csrf
                                            <button type="submit" 
                                                    class="inline-flex items-center justify-center w-full px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                                </svg>
                                                {{ __('Add Instance') }}
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app> 