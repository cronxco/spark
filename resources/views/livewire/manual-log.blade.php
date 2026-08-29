<?php

use App\Integrations\ManualLog\ManualLogPlugin;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;

use function Livewire\Volt\computed;
use function Livewire\Volt\state;

state([
    'actionType' => 'drank_wine',
    'title' => '',
    'rating' => null,
    'notes' => '',
    'saving' => false,
    'savedMessage' => null,
]);

$integration = computed(function () {
    $userId = optional(auth()->guard('web')->user())->id;
    if (! $userId) {
        return null;
    }

    $integrationGroup = IntegrationGroup::firstOrCreate(
        [
            'user_id' => $userId,
            'service' => 'manual_log',
        ],
        [
            'account_id' => null,
            'access_token' => null,
            'refresh_token' => null,
            'expiry' => null,
            'refresh_expiry' => null,
        ]
    );

    return Integration::firstOrCreate(
        [
            'user_id' => $userId,
            'integration_group_id' => $integrationGroup->id,
            'service' => 'manual_log',
            'instance_type' => 'log',
        ],
        [
            'name' => 'Manual Log',
            'configuration' => [],
        ]
    );
});

$actionTypes = computed(fn () => ManualLogPlugin::getActionTypes());

$recentEntries = computed(function () {
    if (! $this->integration) {
        return collect();
    }

    return Event::where('integration_id', $this->integration->id)
        ->with('target')
        ->orderByDesc('time')
        ->limit(10)
        ->get();
});

$save = function (): void {
    $this->validate([
        'actionType' => 'required|string',
        'title' => 'required|string|max:255',
        'rating' => 'nullable|numeric|min:1|max:5',
        'notes' => 'nullable|string|max:1000',
    ]);

    $this->saving = true;

    try {
        if (! $this->integration) {
            return;
        }

        $plugin = new ManualLogPlugin;
        $plugin->createManualEvent(
            $this->integration,
            $this->actionType,
            $this->title,
            $this->rating !== null && $this->rating !== '' ? (float) $this->rating : null,
            $this->notes !== '' ? $this->notes : null,
        );

        $this->title = '';
        $this->rating = null;
        $this->notes = '';
        $this->savedMessage = 'Logged.';
        unset($this->recentEntries);
    } finally {
        $this->saving = false;
    }
};

?>

<div class="max-w-lg space-y-6">
    <div>
        <h2 class="text-lg font-medium text-base-content">Log an Activity</h2>
        <p class="text-sm text-base-content/70">For anything Spark doesn't track automatically.</p>
    </div>

    <form wire:submit="save" class="space-y-4">
        <div class="form-control">
            <label class="label" for="actionType">
                <span class="label-text">What are you logging?</span>
            </label>
            <select wire:model="actionType" id="actionType" class="select select-bordered w-full">
                @foreach ($this->actionTypes as $key => $meta)
                    <option value="{{ $key }}">{{ $meta['display_name'] }}</option>
                @endforeach
            </select>
        </div>

        <div class="form-control">
            <label class="label" for="title">
                <span class="label-text">Title</span>
            </label>
            <input
                wire:model="title"
                id="title"
                type="text"
                class="input input-bordered w-full"
                placeholder="e.g. Rioja Reserva, Dune: Part Two, Catan" />
            @error('title') <span class="text-error text-sm">{{ $message }}</span> @enderror
        </div>

        <div class="form-control">
            <label class="label" for="rating">
                <span class="label-text">Rating (optional)</span>
            </label>
            <div class="flex gap-1">
                @foreach ([1, 2, 3, 4, 5] as $star)
                    <button
                        type="button"
                        wire:click="$set('rating', {{ $star }})"
                        class="btn btn-sm {{ (int) $rating === $star ? 'btn-warning' : 'btn-ghost' }}">
                        {{ $star }}
                    </button>
                @endforeach
                @if ($rating)
                    <button type="button" wire:click="$set('rating', null)" class="btn btn-sm btn-ghost text-base-content/50">
                        clear
                    </button>
                @endif
            </div>
        </div>

        <div class="form-control">
            <label class="label" for="notes">
                <span class="label-text">Notes (optional)</span>
            </label>
            <textarea
                wire:model="notes"
                id="notes"
                rows="2"
                class="textarea textarea-bordered w-full"
                placeholder="Anything worth remembering"></textarea>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">Log it</span>
                <span wire:loading wire:target="save" class="loading loading-spinner loading-xs"></span>
            </button>
            @if ($savedMessage)
                <span class="text-success text-sm">{{ $savedMessage }}</span>
            @endif
        </div>
    </form>

    @if ($this->recentEntries->isNotEmpty())
        <div class="pt-4 border-t border-base-300">
            <h3 class="text-sm font-medium text-base-content/70 mb-2">Recently logged</h3>
            <ul class="space-y-1">
                @foreach ($this->recentEntries as $entry)
                    <li class="text-sm flex items-center justify-between">
                        <span>{{ $entry->target?->title }}</span>
                        <span class="text-base-content/50">
                            {{ $entry->value !== null ? $entry->formatted_value . '/5 · ' : '' }}{{ $entry->time->diffForHumans() }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
