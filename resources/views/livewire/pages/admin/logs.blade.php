<?php

use Livewire\Volt\Component;
use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component {
    //
}; ?>

<div>
    <x-header title="User Logs" subtitle="View your personal activity logs across all integrations" separator />

    <div class="flex flex-col gap-6">
        <livewire:log-viewer type="user" />
    </div>
</div>
