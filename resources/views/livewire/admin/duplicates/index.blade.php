<?php

use App\Services\DuplicateDetectionService;
use Livewire\Volt\Component;
use function Livewire\Volt\{layout, state};

layout('components.layouts.app');

state([
    'modelType' => 'Event',
    'threshold' => 0.95,
    'limit' => 50,
    'duplicates' => [],
    'loading' => false,
]);

$search = function () {
    $this->loading = true;
    $this->duplicates = [];

    $service = app(DuplicateDetectionService::class);
    $userId = (int) auth()->id();

    $results = match ($this->modelType) {
        'Event' => $service->findDuplicateEvents($userId, $this->threshold, $this->limit),
        'Block' => $service->findDuplicateBlocks($userId, $this->threshold, $this->limit),
        'EventObject' => $service->findDuplicateObjects($userId, $this->threshold, $this->limit),
    };

    $this->duplicates = $results->toArray();
    $this->loading = false;
};

?>

<div>
    <x-header title="Duplicate Detection" subtitle="Find potential duplicates using semantic similarity" separator />

    <!-- Controls -->
    <x-card class="mb-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <x-select
                    label="Model Type"
                    wire:model="modelType"
                    :options="[
                        ['id' => 'Event', 'name' => 'Events'],
                        ['id' => 'Block', 'name' => 'Blocks'],
                        ['id' => 'EventObject', 'name' => 'Objects'],
                    ]"
                    option-value="id"
                    option-label="name" />
            </div>
            <div>
                <x-input
                    label="Similarity Threshold"
                    wire:model="threshold"
                    type="number"
                    step="0.01"
                    min="0.80"
                    max="1.00"
                    hint="0.95 = 95% match" />
            </div>
            <div>
                <x-input
                    label="Max Results"
                    wire:model="limit"
                    type="number"
                    min="10"
                    max="500" />
            </div>
            <div class="flex items-end">
                <x-button
                    label="Find Duplicates"
                    wire:click="search"
                    class="btn-primary w-full"
                    :loading="$loading"
                    icon="o-magnifying-glass" />
            </div>
        </div>
    </x-card>

    <!-- Results -->
    @if ($loading)
    <div class="flex items-center justify-center py-12">
        <span class="loading loading-spinner loading-lg text-primary"></span>
        <span class="ml-3 text-lg">Searching for duplicates...</span>
    </div>
    @elseif (!empty($duplicates))
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold">Found {{ count($duplicates) }} potential duplicate pairs</h3>
            <span class="badge badge-info">{{ $modelType }}</span>
        </div>

        @foreach ($duplicates as $index => $pair)
        <x-card class="bg-warning/5 border border-warning/30">
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2">
                    <span class="badge badge-warning">{{ round($pair['similarity'] * 100) }}% match</span>
                    <span class="text-sm text-base-content/70">Pair #{{ $index + 1 }}</span>
                </div>
                <div class="flex items-center gap-2">
                    @if ($modelType === 'Event')
                    <x-button
                        label="View Event 1"
                        link="{{ route('events.show', $pair['model1']['id']) }}"
                        class="btn-sm btn-ghost"
                        icon="o-arrow-top-right-on-square"
                        external />
                    <x-button
                        label="View Event 2"
                        link="{{ route('events.show', $pair['model2']['id']) }}"
                        class="btn-sm btn-ghost"
                        icon="o-arrow-top-right-on-square"
                        external />
                    @elseif ($modelType === 'Block')
                    <x-button
                        label="View Block 1"
                        link="{{ route('blocks.show', $pair['model1']['id']) }}"
                        class="btn-sm btn-ghost"
                        icon="o-arrow-top-right-on-square"
                        external />
                    <x-button
                        label="View Block 2"
                        link="{{ route('blocks.show', $pair['model2']['id']) }}"
                        class="btn-sm btn-ghost"
                        icon="o-arrow-top-right-on-square"
                        external />
                    @elseif ($modelType === 'EventObject')
                    <x-button
                        label="View Object 1"
                        link="{{ route('objects.show', $pair['model1']['id']) }}"
                        class="btn-sm btn-ghost"
                        icon="o-arrow-top-right-on-square"
                        external />
                    <x-button
                        label="View Object 2"
                        link="{{ route('objects.show', $pair['model2']['id']) }}"
                        class="btn-sm btn-ghost"
                        icon="o-arrow-top-right-on-square"
                        external />
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Model 1 -->
                <div class="bg-base-100 rounded-lg p-4 border border-base-300">
                    <h4 class="font-semibold mb-2 flex items-center gap-2">
                        <span class="badge badge-sm">1</span>
                        @if ($modelType === 'Event')
                            {{ $pair['model1']['action'] ?? 'Unknown' }}
                        @elseif ($modelType === 'Block')
                            {{ $pair['model1']['title'] ?? 'Unknown' }}
                        @elseif ($modelType === 'EventObject')
                            {{ $pair['model1']['title'] ?? 'Unknown' }}
                        @endif
                    </h4>
                    <dl class="text-sm space-y-1">
                        <div class="flex justify-between">
                            <dt class="text-base-content/60">ID:</dt>
                            <dd class="font-mono">{{ $pair['model1']['id'] }}</dd>
                        </div>
                        @if ($modelType === 'Event')
                        <div class="flex justify-between">
                            <dt class="text-base-content/60">Time:</dt>
                            <dd>{{ \Carbon\Carbon::parse($pair['model1']['time'])->format('d/m/Y H:i') }}</dd>
                        </div>
                        @if (isset($pair['model1']['value']))
                        <div class="flex justify-between">
                            <dt class="text-base-content/60">Value:</dt>
                            <dd>{{ $pair['model1']['formatted_value'] }} {{ $pair['model1']['value_unit'] }}</dd>
                        </div>
                        @endif
                        @elseif ($modelType === 'Block')
                        <div class="flex justify-between">
                            <dt class="text-base-content/60">Type:</dt>
                            <dd>{{ $pair['model1']['block_type'] }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-base-content/60">Time:</dt>
                            <dd>{{ \Carbon\Carbon::parse($pair['model1']['time'])->format('d/m/Y H:i') }}</dd>
                        </div>
                        @elseif ($modelType === 'EventObject')
                        <div class="flex justify-between">
                            <dt class="text-base-content/60">Concept:</dt>
                            <dd>{{ $pair['model1']['concept'] }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-base-content/60">Type:</dt>
                            <dd>{{ $pair['model1']['type'] }}</dd>
                        </div>
                        @endif
                    </dl>
                </div>

                <!-- Model 2 -->
                <div class="bg-base-100 rounded-lg p-4 border border-base-300">
                    <h4 class="font-semibold mb-2 flex items-center gap-2">
                        <span class="badge badge-sm">2</span>
                        @if ($modelType === 'Event')
                            {{ $pair['model2']['action'] ?? 'Unknown' }}
                        @elseif ($modelType === 'Block')
                            {{ $pair['model2']['title'] ?? 'Unknown' }}
                        @elseif ($modelType === 'EventObject')
                            {{ $pair['model2']['title'] ?? 'Unknown' }}
                        @endif
                    </h4>
                    <dl class="text-sm space-y-1">
                        <div class="flex justify-between">
                            <dt class="text-base-content/60">ID:</dt>
                            <dd class="font-mono">{{ $pair['model2']['id'] }}</dd>
                        </div>
                        @if ($modelType === 'Event')
                        <div class="flex justify-between">
                            <dt class="text-base-content/60">Time:</dt>
                            <dd>{{ \Carbon\Carbon::parse($pair['model2']['time'])->format('d/m/Y H:i') }}</dd>
                        </div>
                        @if (isset($pair['model2']['value']))
                        <div class="flex justify-between">
                            <dt class="text-base-content/60">Value:</dt>
                            <dd>{{ $pair['model2']['formatted_value'] }} {{ $pair['model2']['value_unit'] }}</dd>
                        </div>
                        @endif
                        @elseif ($modelType === 'Block')
                        <div class="flex justify-between">
                            <dt class="text-base-content/60">Type:</dt>
                            <dd>{{ $pair['model2']['block_type'] }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-base-content/60">Time:</dt>
                            <dd>{{ \Carbon\Carbon::parse($pair['model2']['time'])->format('d/m/Y H:i') }}</dd>
                        </div>
                        @elseif ($modelType === 'EventObject')
                        <div class="flex justify-between">
                            <dt class="text-base-content/60">Concept:</dt>
                            <dd>{{ $pair['model2']['concept'] }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-base-content/60">Type:</dt>
                            <dd>{{ $pair['model2']['type'] }}</dd>
                        </div>
                        @endif
                    </dl>
                </div>
            </div>
        </x-card>
        @endforeach
    </div>
    @else
    <x-card>
        <div class="text-center py-12">
            <x-icon name="o-magnifying-glass" class="w-16 h-16 text-base-content/40 mx-auto mb-4" />
            <h3 class="text-lg font-medium text-base-content/70 mb-2">No duplicates found</h3>
            <p class="text-base-content/60">Click "Find Duplicates" to search for similar records</p>
        </div>
    </x-card>
    @endif
</div>
