<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\Attributes\On;

new class extends Component {
    public string $tokenName = '';
    public array $tokens = [];
    public bool $showNewToken = false;
    public string $newToken = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->loadTokens();
    }

    /**
     * Load user's tokens.
     */
    public function loadTokens(): void
    {
        $user = Auth::user();
        $this->tokens = $user->tokens()->get()->map(function ($token) {
            return [
                'id' => $token->id,
                'name' => $token->name,
                'created_at' => $token->created_at,
                'last_used_at' => $token->last_used_at,
            ];
        })->toArray();
    }

    /**
     * Create a new API token.
     */
    public function createToken(): void
    {
        $this->validate([
            'tokenName' => 'required|string|max:255',
        ]);

        try {
            $user = Auth::user();
            $token = $user->createToken($this->tokenName);

            $this->newToken = $token->plainTextToken;
            $this->showNewToken = true;
            $this->tokenName = '';
            $this->loadTokens();
        } catch (\Exception $e) {
            // Handle any errors that might occur during token creation
            session()->flash('error', 'Failed to create token: ' . $e->getMessage());
        }
    }

    /**
     * Revoke a token.
     */
    public function revokeToken($tokenId): void
    {
        try {
            $user = Auth::user();
            $token = $user->tokens()->find($tokenId);

            if ($token) {
                $token->delete();
                $this->loadTokens();
            }
        } catch (\Exception $e) {
            session()->flash('error', 'Failed to revoke token: ' . $e->getMessage());
        }
    }

    /**
     * Hide the new token display.
     */
    public function hideNewToken(): void
    {
        $this->showNewToken = false;
        $this->newToken = '';
    }
}; ?>

<section class="w-full">
  @include('partials.settings-heading')
    <x-settings.layout :heading="__('API Tokens')" :subheading="__('Manage your API tokens for accessing the API')">
    </div>
        <!-- Create New Token -->
        <div class="my-6 w-full space-y-6">
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg max-w-lg">
                
                    <h3 class="text-lg font-medium leading-6 text-gray-900 dark:text-gray-100">
                        {{ __('Create New Token') }}
                    </h3>
                    <div class="mt-2 max-w-xl text-sm text-gray-500 dark:text-gray-400">
                        <p>{{ __('Create a new API token to access the API.') }}</p>
                    </div>
                    <form wire:submit.prevent="createToken" class="my-6 w-full space-y-6">
                        <div class="w-full sm:max-w-xs">
                            <flux:input 
                                wire:model="tokenName" 
                                :label="__('Token Name')" 
                                type="text" 
                                required 
                                autocomplete="off"
                                placeholder="My API Token"
                            />
                        </div>

                        <div class="flex items-center gap-4">
                            <div class="flex items-center justify-end">
                              <flux:button variant="primary" type="submit" class="w-full">{{ __('Create Token') }}</flux:button>
                            </div>            
                        </div>
                        
                    </form>
                
            </div>

            <!-- New Token Display -->
            @if($showNewToken)
            <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-green-800 dark:text-green-200">
                            {{ __('Token Created Successfully') }}
                        </h3>
                        <div class="mt-2 text-sm text-green-700 dark:text-green-300">
                            <p>{{ __('Please copy your new API token. For your security, it won\'t be shown again.') }}</p>
                        </div>
                        <div class="mt-3">
                            <div class="flex items-center space-x-2">
                                <input 
                                    type="text" 
                                    value="{{ $newToken }}" 
                                    readonly 
                                    class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm font-mono"
                                />
                                <button 
                                    type="button"
                                    class="inline-flex items-center px-3 py-2 border border-gray-300 dark:border-gray-600 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                                    onclick="navigator.clipboard.writeText('{{ $newToken }}')"
                                >
                                    {{ __('Copy') }}
                                </button>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button 
                                type="button"
                                class="inline-flex items-center px-3 py-2 border border-gray-300 dark:border-gray-600 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                                wire:click="hideNewToken"
                            >
                                {{ __('Close') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            @endif

            <!-- Existing Tokens -->
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                    <h3 class="text-lg font-medium leading-6 text-gray-900 dark:text-gray-100">
                        {{ __('Your API Tokens') }}
                    </h3>
                    <div class="mt-2 max-w-xl text-sm text-gray-500 dark:text-gray-400">
                        <p>{{ __('Manage your existing API tokens.') }}</p>
                    </div>
                    
                    @if(count($tokens) > 0)
                        <div class="mt-5">
                            <div class="overflow-hidden shadow ring-1 ring-black ring-opacity-5 md:rounded-lg">
                                <table class="min-w-full divide-y divide-gray-300 dark:divide-gray-600 border-separate">
                                    <thead class="bg-gray-50 dark:bg-gray-700">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                                {{ __('Name') }}
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                                {{ __('Created') }}
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                                {{ __('Last Used') }}
                                            </th>
                                            <th scope="col" class="relative px-6 py-3">
                                                <span class="sr-only">{{ __('Actions') }}</span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                        @foreach($tokens as $token)
                                        <tr class="mt-6">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                                                {{ $token['name'] }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                {{ \Carbon\Carbon::parse($token['created_at'])->format('M j, Y g:i A') }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                @if($token['last_used_at'])
                                                    {{ \Carbon\Carbon::parse($token['last_used_at'])->format('M j, Y g:i A') }}
                                                @else
                                                    <span class="text-gray-400 dark:text-gray-500">{{ __('Never') }}</span>
                                                @endif
                                          
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            <form wire:submit="revokeToken({{ $token['id'] }})" wire:confirm="{{ __('Are you sure you want to revoke this token?') }}">
                                              <flux:button variant="danger" type="submit" size="sm" class="px-2 my-6">
                                                {{ __('Revoke') }}
                                              </flux:button>
                                            </form>
                                            </td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @else
                        <div class="mt-5 text-center py-8">
                            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('No tokens') }}</h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Get started by creating a new API token.') }}</p>
                        </div>
                    @endif
            </div>
        </div>
    </x-settings.layout>
</section> 