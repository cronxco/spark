@props(['block'])

@php
use App\Support\FlintQuestion;

$question = $block->metadata['question'] ?? $block->title;
$topic = $block->metadata['topic'] ?? null;
$retired = FlintQuestion::isRetired($block);
@endphp

<x-block-card.shell :block="$block" :linked="false" :title="$question">
    @if ($topic || $retired)
        <div class="flex items-center gap-1.5 flex-wrap">
            {{-- Priority is Flint's internal ordering, not something the
                 reader is asked to weigh (ratified decision D11). --}}
            @if ($topic)
                <span class="badge badge-ghost badge-sm">{{ ucfirst($topic) }}</span>
            @endif
            @if ($retired)
                {{-- Still answerable: retirement stops it presenting as
                     outstanding, it does not close the question. --}}
                <span class="badge badge-ghost badge-sm">Stale</span>
            @endif
        </div>
    @endif

    @livewire('answer-flint-question', ['block' => $block], key('answer-' . $block->id))

    <x-slot:context>
        @livewire('block-feedback', ['block' => $block], key('feedback-' . $block->id))
    </x-slot:context>
</x-block-card.shell>
