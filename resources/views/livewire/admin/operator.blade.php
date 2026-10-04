<?php

use App\Models\User;
use App\Support\AdminTenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    use Toast;

    public string $email = '';

    public string $reason = '';

    public function start(): void
    {
        $this->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ], [
            'email.exists' => 'No user has that email address.',
            'reason.min' => 'Give a reason of at least 10 characters.',
        ]);

        try {
            AdminTenant::start(Auth::user(), User::where('email', $this->email)->firstOrFail(), $this->reason);
        } catch (InvalidArgumentException $exception) {
            $this->addError('email', $exception->getMessage());

            return;
        }

        $this->reset(['email', 'reason']);
        $this->success('Admin pages now show this user\'s data. Every page you open is logged.');
    }

    public function stop(): void
    {
        AdminTenant::end();
        $this->success('Back to your own data.');
    }

    public function with(): array
    {
        $context = AdminTenant::context();

        return [
            'isOperator' => AdminTenant::isGlobalOperator(Auth::user()),
            'context' => $context,
            'target' => $context ? User::find($context['user_id']) : null,
        ];
    }
}; ?>

<div>
    <x-header title="Operator access" subtitle="View one other user's data, with a reason, for a limited time" separator />

    @if (! $isOperator)
        <x-alert icon="fas.lock" class="alert-info">
            Admin pages only show your own data. Viewing another user's data needs a global operator, set in
            <code>SPARK_GLOBAL_OPERATORS</code>.
        </x-alert>
    @elseif ($context && $target)
        <x-card>
            <div class="flex flex-col gap-4">
                <x-alert icon="fas.user-shield" class="alert-warning">
                    Admin pages are showing <strong>{{ $target->name }}</strong> ({{ $target->email }}) until
                    {{ \Illuminate\Support\Carbon::parse($context['expires_at'])->timezone(Auth::user()->getTimezone())->format('H:i') }}.
                    Reason: {{ $context['reason'] }}
                </x-alert>
                <div>
                    <x-button label="Back to my data" icon="fas.arrow-rotate-left" wire:click="stop" class="btn-primary" />
                </div>
            </div>
        </x-card>
    @else
        <x-card>
            <form wire:submit="start" class="flex flex-col gap-4">
                <x-input label="User's email" wire:model="email" type="email" />
                <x-textarea label="Reason" wire:model="reason" hint="Recorded in the security log with every page you open." rows="3" />
                <div>
                    <x-button type="submit" label="View their data" icon="fas.user-shield" class="btn-warning" spinner="start" />
                </div>
            </form>
        </x-card>
    @endif
</div>
