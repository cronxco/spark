<?php

use App\Integrations\DailyCheckin\DailyCheckinPlugin;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Carbon\Carbon;

use function Livewire\Volt\computed;
use function Livewire\Volt\mount;
use function Livewire\Volt\on;
use function Livewire\Volt\state;

state([
    'date' => null,
    'amPhysical' => null,
    'amMental' => null,
    'pmPhysical' => null,
    'pmMental' => null,
    'activeView' => 'am', // 'am' or 'pm'
    'saving' => false,
    'lastSavedAt' => null,
]);

mount(function (?string $date = null) {
    $this->date = $date ?? Carbon::today()->format('Y-m-d');

    // Determine default view based on current hour
    $currentHour = Carbon::now()->hour;
    $this->activeView = $currentHour < 12 ? 'am' : 'pm';

    // Load existing check-ins
    $this->loadCheckins();
});

$loadCheckins = function (): void {
    $userId = optional(auth()->guard('web')->user())->id;
    if (! $userId) {
        return;
    }

    $plugin = new DailyCheckinPlugin;
    $checkins = $plugin->getCheckinsForDate($userId, $this->date);

    // Load morning check-in
    if ($checkins['morning']) {
        $metadata = $checkins['morning']->event_metadata ?? [];
        $this->amPhysical = $metadata['physical_energy'] ?? null;
        $this->amMental = $metadata['mental_energy'] ?? null;
    }

    // Load afternoon check-in
    if ($checkins['afternoon']) {
        $metadata = $checkins['afternoon']->event_metadata ?? [];
        $this->pmPhysical = $metadata['physical_energy'] ?? null;
        $this->pmMental = $metadata['mental_energy'] ?? null;
    }

    // Update parent status after loading
    $this->dispatch('checkin-status-updated', status: $this->completionStatus)->self();
    $this->js("window.dispatchEvent(new CustomEvent('checkin-status-updated', { detail: { status: '{$this->completionStatus}' }}));");
};

$integration = computed(function () {
    $userId = optional(auth()->guard('web')->user())->id;
    if (! $userId) {
        return null;
    }

    // Find or create the daily check-in integration for this user
    $integrationGroup = IntegrationGroup::firstOrCreate(
        [
            'user_id' => $userId,
            'service' => 'daily_checkin',
        ],
        [
            'account_id' => null,
            'access_token' => null,
            'refresh_token' => null,
            'expiry' => null,
            'refresh_expiry' => null,
        ]
    );

    $integration = Integration::firstOrCreate(
        [
            'user_id' => $userId,
            'integration_group_id' => $integrationGroup->id,
            'service' => 'daily_checkin',
            'instance_type' => 'checkin',
        ],
        [
            'name' => 'Daily Check-in',
            'configuration' => [],
        ]
    );

    return $integration;
});

$saveCheckin = function (string $period): void {
    if ($period === 'morning' && $this->amPhysical && $this->amMental) {
        $this->saving = true;

        try {
            if (! $this->integration) {
                return;
            }

            $plugin = new DailyCheckinPlugin;
            $plugin->createCheckinEvent(
                $this->integration,
                'morning',
                (int) $this->amPhysical,
                (int) $this->amMental,
                $this->date
            );

            $this->lastSavedAt = now()->toIso8601String();
            $this->dispatch('checkin-saved');
            $this->dispatch('$refresh');
            $this->dispatch('checkin-status-updated', status: $this->completionStatus)->self();
            $this->js("window.dispatchEvent(new CustomEvent('checkin-status-updated', { detail: { status: '{$this->completionStatus}' }}));");
        } catch (\Throwable $e) {
            // Silently fail - user can retry
        } finally {
            $this->saving = false;
        }
    } elseif ($period === 'afternoon' && $this->pmPhysical && $this->pmMental) {
        $this->saving = true;

        try {
            if (! $this->integration) {
                return;
            }

            $plugin = new DailyCheckinPlugin;
            $plugin->createCheckinEvent(
                $this->integration,
                'afternoon',
                (int) $this->pmPhysical,
                (int) $this->pmMental,
                $this->date
            );

            $this->lastSavedAt = now()->toIso8601String();
            $this->dispatch('checkin-saved');
            $this->dispatch('$refresh');
            $this->dispatch('checkin-status-updated', status: $this->completionStatus)->self();
            $this->js("window.dispatchEvent(new CustomEvent('checkin-status-updated', { detail: { status: '{$this->completionStatus}' }}));");
        } catch (\Throwable $e) {
            // Silently fail - user can retry
        } finally {
            $this->saving = false;
        }
    }
};

$updatedAmPhysical = function ($value): void {
    if ($value && $this->amMental) {
        $this->saveCheckin('morning');
    }
    $this->dispatch('checkin-status-updated', status: $this->completionStatus)->self();
    $this->js("window.dispatchEvent(new CustomEvent('checkin-status-updated', { detail: { status: '{$this->completionStatus}' }}));");
};

$updatedAmMental = function ($value): void {
    if ($value && $this->amPhysical) {
        $this->saveCheckin('morning');
    }
    $this->dispatch('checkin-status-updated', status: $this->completionStatus)->self();
    $this->js("window.dispatchEvent(new CustomEvent('checkin-status-updated', { detail: { status: '{$this->completionStatus}' }}));");
};

$updatedPmPhysical = function ($value): void {
    if ($value && $this->pmMental) {
        $this->saveCheckin('afternoon');
    }
    $this->dispatch('checkin-status-updated', status: $this->completionStatus)->self();
    $this->js("window.dispatchEvent(new CustomEvent('checkin-status-updated', { detail: { status: '{$this->completionStatus}' }}));");
};

$updatedPmMental = function ($value): void {
    if ($value && $this->pmPhysical) {
        $this->saveCheckin('afternoon');
    }
    $this->dispatch('checkin-status-updated', status: $this->completionStatus)->self();
    $this->js("window.dispatchEvent(new CustomEvent('checkin-status-updated', { detail: { status: '{$this->completionStatus}' }}));");
};

$switchView = function (string $view): void {
    $this->activeView = $view;
};

$completionStatus = computed(function () {
    $currentHour = Carbon::now()->hour;
    $isViewingToday = Carbon::parse($this->date)->isToday();

    $morningComplete = $this->amPhysical && $this->amMental;
    $afternoonComplete = $this->pmPhysical && $this->pmMental;

    // If viewing a past date or future date, ignore time-based logic
    if (! $isViewingToday) {
        if ($morningComplete && $afternoonComplete) {
            return 'green';
        } elseif ($morningComplete || $afternoonComplete) {
            return 'amber';
        } else {
            return 'red';
        }
    }

    // Time-based logic for today
    if ($currentHour < 12) {
        // Morning
        return $morningComplete ? 'green' : 'amber';
    } else {
        // Afternoon
        if ($morningComplete && $afternoonComplete) {
            return 'green';
        } elseif ($morningComplete) {
            return 'amber';
        } else {
            return 'red';
        }
    }
});

?>

<div class="flex items-center justify-between gap-4 flex-wrap">
    <!-- Tabs for AM/PM -->
    <div role="tablist" class="tabs tabs-boxed tabs-sm flex-none">
        <a
            role="tab"
            class="tab tab-sm gap-1.5 {{ $activeView === 'am' ? 'tab-active' : '' }}"
            wire:click="switchView('am')">
            <x-icon name="o-sun" class="w-3.5 h-3.5" />
            AM
        </a>
        <a
            role="tab"
            class="tab tab-sm gap-1.5 {{ $activeView === 'pm' ? 'tab-active' : '' }}"
            wire:click="switchView('pm')">
            <x-icon name="o-moon" class="w-3.5 h-3.5" />
            PM
        </a>
    </div>

    <!-- Morning View -->
    @if ($activeView === 'am')
        <div class="flex items-center gap-2 flex-1 justify-center">
            <x-icon name="o-bolt" class="w-4 h-4 text-warning" x-tooltip="Physical Energy" />
            <x-rating wire:model.live="amPhysical" />
        </div>
        <div class="flex items-center gap-2 flex-1 justify-center">
            <x-icon name="o-light-bulb" class="w-4 h-4 text-info" x-tooltip="Mental Energy" />
            <x-rating wire:model.live="amMental" />
            @if ($amPhysical && $amMental)
                <x-icon name="o-check-circle" class="w-4 h-4 text-success ml-1" x-tooltip="Complete" />
            @endif
            @if ($saving)
                <span class="loading loading-spinner loading-xs text-info ml-1"></span>
            @endif
        </div>
    @endif

    <!-- Afternoon View -->
    @if ($activeView === 'pm')
        <div class="flex items-center gap-2 flex-1 justify-center">
            <x-icon name="o-bolt" class="w-4 h-4 text-warning" x-tooltip="Physical Energy" />
            <x-rating wire:model.live="pmPhysical" />
        </div>
        <div class="flex items-center gap-2 flex-1 justify-center">
            <x-icon name="o-light-bulb" class="w-4 h-4 text-info" x-tooltip="Mental Energy" />
            <x-rating wire:model.live="pmMental" />
            @if ($pmPhysical && $pmMental)
                <x-icon name="o-check-circle" class="w-4 h-4 text-success ml-1" x-tooltip="Complete" />
            @endif
            @if ($saving)
                <span class="loading loading-spinner loading-xs text-info ml-1"></span>
            @endif
        </div>
    @endif
</div>
