<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\Attributes\On;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

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

            $this->success('API token created successfully!');
        } catch (\Exception $e) {
            $this->error('Failed to create token: ' . $e->getMessage());
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
                $this->success('Token revoked successfully!');
            }
        } catch (\Exception $e) {
            $this->error('Failed to revoke token: ' . $e->getMessage());
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

    /**
     * Copy token to clipboard.
     */
    public function copyToken(): void
    {
        $this->success('Token copied to clipboard!');
    }
}; ?>

<div>
    <x-header title="{{ __('API Tokens') }}" subtitle="{{ __('Manage API tokens for your account') }}" separator />

    <div class="space-y-4 lg:space-y-6">
    <!-- Create Token Card -->
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <h3 class="text-lg font-semibold mb-4">{{ __('Create New Token') }}</h3>
            <p class="text-sm text-base-content/70 mb-4">{{ __('Create a new API token to access the API') }}</p>

            <div class="form-control">
                <label class="label">
                    <span class="label-text">{{ __('Token Name') }}</span>
                </label>
                <input
                    wire:model="tokenName"
                    type="text"
                    placeholder="Enter token name"
                    class="input input-bordered w-full"
                    required
                    autocomplete="off"
                />
            </div>

            <div class="flex justify-end mt-4">
                <x-button
                    label="{{ __('Create Token') }}"
                    wire:click="createToken"
                    class="btn-primary"
                    spinner="createToken"
                />
            </div>
        </div>
    </div>

    <!-- New Token Display -->
    @if ($showNewToken)
        <div class="card bg-success/10 border border-success/20">
            <div class="card-body">
                <h3 class="text-lg font-semibold text-success mb-2">{{ __('Token Created Successfully') }}</h3>
                <p class="text-sm text-base-content/70 mb-4">{{ __('Please copy your new API token. For your security, it won\'t be shown again.') }}</p>

                <div class="form-control">
                    <input
                        type="text"
                        value="{{ $newToken }}"
                        readonly
                        class="input input-bordered w-full font-mono text-sm"
                    />
                </div>

                <div class="flex justify-end gap-2 mt-4">
                    <x-button
                        label="{{ __('Copy') }}"
                        class="btn-success"
                        onclick="navigator.clipboard.writeText('{{ $newToken }}').then(() => { $wire.copyToken(); })"
                    />
                    <x-button
                        label="{{ __('Close') }}"
                        class="btn-outline"
                        wire:click="hideNewToken"
                    />
                </div>
            </div>
        </div>
    @endif

    <!-- Token List Card -->
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <h3 class="text-lg font-semibold mb-4">{{ __('Your Tokens') }}</h3>

            @if (count($tokens) > 0)
                <div class="overflow-x-auto">
                    <table class="table table-zebra">
                        <thead>
                            <tr>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Created') }}</th>
                                <th>{{ __('Last Used') }}</th>
                                <th class="text-right">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tokens as $token)
                                <tr>
                                    <td class="font-medium">{{ $token['name'] }}</td>
                                    <td>{{ \Carbon\Carbon::parse($token['created_at'])->format('M j, Y g:i A') }}</td>
                                    <td>
                                        @if ($token['last_used_at'])
                                            {{ \Carbon\Carbon::parse($token['last_used_at'])->format('M j, Y g:i A') }}
                                        @else
                                            <span class="text-base-content/50">{{ __('Never') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <x-button
                                            label="{{ __('Revoke') }}"
                                            class="btn-sm btn-error"
                                            wire:click="revokeToken({{ $token['id'] }})"
                                            wire:confirm="{{ __('Are you sure you want to revoke this token?') }}"
                                        />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-center py-8">
                    <x-icon name="o-document-text" class="w-12 h-12 mx-auto text-base-content/30" />
                    <h3 class="mt-2 text-sm font-medium">{{ __('No tokens') }}</h3>
                    <p class="mt-1 text-sm text-base-content/70">{{ __('Get started by creating a new API token.') }}</p>
                </div>
            @endif
        </div>
    </div>
    </div>
</div> 