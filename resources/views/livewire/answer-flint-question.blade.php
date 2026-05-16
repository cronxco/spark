<div>
    @php
        $answerOptions = $block->metadata['answer_options'] ?? null;
    @endphp

    @if ($answered)
        <div class="space-y-2">
            <div class="flex items-center gap-2">
                <x-icon name="o-check-circle" class="w-4 h-4 text-success" />
                <span class="text-sm font-medium text-success">Answered</span>
            </div>
            <div class="bg-base-100 rounded-lg p-3 border border-success/30 text-sm text-base-content">
                {{ $answer }}
            </div>
            @if ($answer_note)
                <div class="text-xs text-base-content/60 italic pl-1">{{ $answer_note }}</div>
            @endif
        </div>
    @else
        <form wire:submit="submit" class="space-y-3">
            @if ($answerOptions)
                <div class="flex flex-wrap gap-2">
                    @foreach ($answerOptions as $option)
                        <button
                            type="button"
                            wire:click="$set('answer', '{{ $option }}')"
                            class="btn btn-sm {{ $answer === $option ? 'btn-primary' : 'btn-outline' }}"
                        >
                            {{ $option }}
                        </button>
                    @endforeach
                </div>
            @else
                <textarea
                    wire:model="answer"
                    class="textarea textarea-bordered w-full text-sm"
                    rows="3"
                    placeholder="Your answer..."
                ></textarea>
            @endif

            <textarea
                wire:model="answer_note"
                class="textarea textarea-bordered w-full text-sm"
                rows="2"
                placeholder="Any additional notes? (optional)"
            ></textarea>

            @error('answer')
                <div class="text-error text-xs">{{ $message }}</div>
            @enderror

            <button
                type="submit"
                class="btn btn-primary btn-sm"
                wire:loading.attr="disabled"
            >
                <span wire:loading.remove>Save Answer</span>
                <span wire:loading>Saving…</span>
            </button>
        </form>
    @endif
</div>
