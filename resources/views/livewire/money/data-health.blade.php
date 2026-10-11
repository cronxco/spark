<div @if ($progress && $progress->isInProgress()) wire:poll.2s @endif>
    <div class="card bg-base-200 shadow">
        <div class="card-body gap-4">
            <div>
                <h2 class="card-title">Data health</h2>
                <p class="text-sm text-base-content/70">
                    Spark checks your money data for duplicates and leftovers. Nothing changes until you press Apply.
                </p>
            </div>

            @if ($progress)
                <div class="alert {{ $progress->isFailed() ? 'alert-error' : ($progress->isCompleted() ? 'alert-success' : 'alert-info') }}">
                    <div class="flex flex-col gap-1 w-full">
                        <span class="font-medium">{{ $progress->isFailed() ? $progress->error_message : $progress->message }}</span>
                        @if ($progress->isCompleted())
                            <span class="text-sm">
                                Applied {{ $progress->details['applied'] ?? 0 }} changes{{ ($progress->details['skipped'] ?? 0) > 0 ? ', skipped '.$progress->details['skipped'] : '' }}.
                            </span>
                        @elseif (! $progress->isFailed())
                            <progress class="progress progress-primary w-full" value="{{ $progress->progress }}" max="{{ $progress->total }}"></progress>
                        @endif
                    </div>
                </div>
            @endif

            @if (count($steps) === 0)
                <div class="alert alert-success">
                    <x-icon name="fas.circle-check" class="w-5 h-5" />
                    <span>Everything looks tidy. There is nothing to clean up.</span>
                </div>
            @else
                <ul class="flex flex-col divide-y divide-base-300">
                    @foreach ($steps as $step)
                        <li class="py-3 flex flex-col gap-2" wire:key="step-{{ $step['key'] }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex flex-col">
                                    <span class="font-medium">{{ $step['label'] }}</span>
                                    <span class="text-sm text-base-content/70">{{ $step['detail'] }}</span>
                                </div>
                                @if ($step['blocked'])
                                    <span class="badge badge-warning shrink-0">Blocked</span>
                                @elseif ($step['needs_input'])
                                    <span class="badge badge-ghost shrink-0">Needs a name</span>
                                @else
                                    <span class="badge badge-info shrink-0">Ready</span>
                                @endif
                            </div>

                            @if ($step['blocked'])
                                <span class="text-sm text-warning">{{ $step['blocked'] }}. This one is skipped until it is resolved.</span>
                            @endif

                            @if ($step['needs_input'])
                                @php $categoryId = $step['payload']['category_id']; @endphp
                                <form wire:submit="saveCategoryName('{{ $categoryId }}')" class="flex gap-2 items-start">
                                    <div class="flex flex-col gap-1">
                                        <input
                                            type="text"
                                            wire:model="categoryNames.{{ $categoryId }}"
                                            placeholder="Category name, e.g. Lunch"
                                            class="input input-bordered input-sm w-64" />
                                        @error('categoryNames.'.$categoryId)
                                            <span class="text-xs text-error">{{ $message }}</span>
                                        @enderror
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-outline">Save name</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>

                <div class="card-actions justify-end items-center gap-3">
                    <span class="text-sm text-base-content/70">{{ $runnable }} of {{ count($steps) }} ready to apply</span>
                    <button
                        wire:click="apply('{{ $fingerprint }}')"
                        wire:confirm="Apply {{ $runnable }} reviewed changes? Merged accounts keep an alias and removed rows can be restored."
                        wire:loading.attr="disabled"
                        @disabled($runnable === 0 || ($progress && $progress->isInProgress()))
                        class="btn btn-primary btn-sm">
                        Apply
                    </button>
                </div>
            @endif
        </div>
    </div>
</div>
