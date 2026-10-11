<?php

use App\Support\AdminTenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    use Toast;

    public function with(): array
    {
        return [
            'isOperator' => AdminTenant::isGlobalOperator(Auth::user()),
            'context' => AdminTenant::context(),
        ];
    }
}; ?>

<div>
    <x-header title="God Mode" subtitle="Show everything across all users on the admin pages" separator />

    @if (! $isOperator)
        <x-alert icon="fas.lock" class="alert-info">
            Admin pages only show your own data. God Mode needs a global operator, set in
            <code>SPARK_GLOBAL_OPERATORS</code>.
        </x-alert>
    @elseif ($context)
        <x-card>
            <div class="flex flex-col gap-4">
                <x-alert icon="fas.user-shield" class="alert-warning">
                    God Mode is on until
                    {{ \Illuminate\Support\Carbon::parse($context['expires_at'])->timezone(Auth::user()->getTimezone())->format('H:i') }}.
                    Admin pages show everything across all users, and every page you open is logged.
                </x-alert>
                <form method="POST" action="{{ route('admin.god-mode.disable') }}">
                    @csrf
                    <x-button label="Turn off God Mode" icon="fas.arrow-rotate-left" type="submit" class="btn-primary" />
                </form>
            </div>
        </x-card>
    @else
        <x-card>
            <div class="flex flex-col gap-4">
                <p>
                    God Mode makes every admin page show everything across all users, including bulk actions such as
                    emptying the bin. It turns off by itself after {{ config('spark.admin.god_mode_minutes') }} minutes.
                    Turning it on and every page you open are written to the security log.
                </p>
                <p>You will be asked to sign in with Authelia again to confirm it is you.</p>
                <form method="POST" action="{{ route('admin.god-mode.enable') }}">
                    @csrf
                    <x-button label="Turn on God Mode" icon="fas.user-shield" type="submit" class="btn-warning" />
                </form>
            </div>
        </x-card>
    @endif
</div>
