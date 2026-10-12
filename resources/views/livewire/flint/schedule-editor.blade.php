<div class="flex flex-col gap-4">
    @foreach ($routines as $routine => $label)
        <div class="flex flex-col gap-3" wire:key="schedule-{{ $routine }}">
            <span class="text-xs text-base-content/60">{{ $next[$routine] ? 'Next run '.$next[$routine] : 'Off' }}</span>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="form-control">
                    <span class="label-text">How often</span>
                    <select class="select select-bordered select-sm" wire:model.live="forms.{{ $routine }}.frequency">
                        <option value="daily">Every day</option>
                        <option value="weekdays">Weekdays</option>
                        <option value="weekly">On chosen days</option>
                        <option value="monthly">Once a month</option>
                    </select>
                    @error("forms.{$routine}.frequency") <span class="text-error text-xs">{{ $message }}</span> @enderror
                </label>

                @if (($forms[$routine]['frequency'] ?? '') === 'monthly')
                    <label class="form-control">
                        <span class="label-text">Day of the month</span>
                        <select class="select select-bordered select-sm" wire:model="forms.{{ $routine }}.day_of_month">
                            @foreach (range(1, 28) as $day)
                                <option value="{{ $day }}">{{ $day }}</option>
                            @endforeach
                            <option value="last">Last day</option>
                            <option value="first_working">First working day</option>
                        </select>
                        @error("forms.{$routine}.day_of_month") <span class="text-error text-xs">{{ $message }}</span> @enderror
                    </label>
                @endif
            </div>

            @if (($forms[$routine]['frequency'] ?? '') === 'weekly')
                <fieldset class="flex flex-wrap gap-3" aria-label="Days of the week">
                    @foreach (['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'] as $value => $dayLabel)
                        <label class="flex items-center gap-1 text-sm">
                            <input type="checkbox" class="checkbox checkbox-sm" value="{{ $value }}" wire:model="forms.{{ $routine }}.days" />
                            {{ $dayLabel }}
                        </label>
                    @endforeach
                </fieldset>
                @error("forms.{$routine}.days") <span class="text-error text-xs">{{ $message }}</span> @enderror
            @endif

            <div class="flex flex-col gap-2">
                <span class="label-text">Times</span>
                @foreach ($forms[$routine]['times'] as $index => $time)
                    <div class="flex items-center gap-2" wire:key="time-{{ $routine }}-{{ $index }}">
                        <input type="time" class="input input-bordered input-sm" wire:model="forms.{{ $routine }}.times.{{ $index }}" aria-label="{{ $label }} time {{ $index + 1 }}" />
                        @if (count($forms[$routine]['times']) > 1 || ($forms[$routine]['cron'] ?? '') !== '')
                            <button type="button" class="btn btn-ghost btn-xs" wire:click="removeTime('{{ $routine }}', {{ $index }})" aria-label="Remove time {{ $index + 1 }}">Remove</button>
                        @endif
                    </div>
                    @error("forms.{$routine}.times.{$index}") <span class="text-error text-xs">{{ $message }}</span> @enderror
                @endforeach
                @error("forms.{$routine}.times") <span class="text-error text-xs">{{ $message }}</span> @enderror
                <button type="button" class="btn btn-ghost btn-xs self-start" wire:click="addTime('{{ $routine }}')">Add another time</button>
            </div>

            <details @if (($forms[$routine]['cron'] ?? '') !== '') open @endif>
                <summary class="cursor-pointer text-sm">Advanced: cron expression</summary>
                <label class="form-control mt-2 max-w-sm">
                    <span class="label-text">Five fields: minute hour day month weekday</span>
                    <input type="text" class="input input-bordered input-sm font-mono" placeholder="0 9 * * 1-5" wire:model="forms.{{ $routine }}.cron" />
                    @error("forms.{$routine}.cron") <span class="text-error text-xs">{{ $message }}</span> @enderror
                </label>
            </details>

        </div>
    @endforeach
</div>
