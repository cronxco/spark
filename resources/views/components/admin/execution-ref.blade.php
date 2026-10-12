@props(['execution', 'entity' => null])

@if ($entity instanceof \App\Models\Event)
    <x-event-ref :event="$entity" />
@elseif ($entity instanceof \App\Models\EventObject)
    <x-object-ref :object="$entity" />
@elseif ($entity instanceof \App\Models\Block)
    <x-block-ref :block="$entity" />
@else
    <span class="text-sm text-base-content/60" title="{{ $execution->entity_id }}">
        {{ ucfirst($execution->entity_type) }} #{{ \Illuminate\Support\Str::limit($execution->entity_id, 8, '') }} (gone)
    </span>
@endif
