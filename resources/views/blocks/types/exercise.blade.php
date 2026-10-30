@props(['block'])

@php
$exerciseName = $block->metadata['exercise_name'] ?? $block->title;
$weight = $block->formatted_value ?? $block->metadata['weight'] ?? null;
$unit = $block->value_unit ?? $block->metadata['unit'] ?? 'kg';
@endphp

<x-block-card.shell :block="$block" :title="$exerciseName">
    <x-block-card.stats :stats="[
        'Set' => $block->metadata['set_number'] ?? null,
        'Reps' => $block->metadata['reps'] ?? null,
        'Weight' => is_numeric($weight) ? number_format($weight) . ' ' . $unit : null,
        'RPE' => $block->metadata['rpe'] ?? null,
    ]" />
</x-block-card.shell>
