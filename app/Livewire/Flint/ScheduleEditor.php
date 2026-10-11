<?php

namespace App\Livewire\Flint;

use App\Services\Flint\FlintScheduleService;
use App\Services\Flint\FlintScheduleSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Edits when each repeating Flint routine runs: one or more local times, which days, and an optional
 * advanced cron expression. Everything is checked by {@see FlintScheduleSettings::validateSchedule()}
 * before it is stored, so a bad value is rejected here instead of silently falling back to a default.
 * The Flint settings page renders one editor per routine and saves them all with its own Save button;
 * the on/off switch belongs to the page, not to this editor.
 */
class ScheduleEditor extends Component
{
    /** @var array<string, array<string, mixed>> */
    public array $forms = [];

    public ?string $saved = null;

    /** The one routine this editor shows and saves, or null for every editable routine. */
    public ?string $routine = null;

    public function mount(?string $routine = null): void
    {
        abort_if($routine !== null && ! in_array($routine, FlintScheduleSettings::EDITABLE_ROUTINES, true), 404);
        $this->routine = $routine;

        $settings = Auth::user()->settings['flint'] ?? [];
        $service = app(FlintScheduleService::class);

        foreach (FlintScheduleSettings::EDITABLE_ROUTINES as $routine) {
            $cadence = $service->cadence(Auth::user(), $routine) ?? [];
            $this->forms[$routine] = [
                'frequency' => $cadence['frequency'] ?? 'daily',
                'days' => $cadence['days'] ?? [],
                'day_of_month' => (string) ($cadence['day_of_month'] ?? '1'),
                'times' => $cadence['times'] ?? (isset($cadence['cron']) ? [] : [$service->slot(Auth::user(), $routine)]),
                'cron' => $cadence['cron'] ?? '',
            ];
        }
    }

    public function addTime(string $routine): void
    {
        $this->guard($routine);
        $this->forms[$routine]['times'][] = '09:00';
    }

    public function removeTime(string $routine, int $index): void
    {
        $this->guard($routine);
        unset($this->forms[$routine]['times'][$index]);
        $this->forms[$routine]['times'] = array_values($this->forms[$routine]['times']);
    }

    public function save(string $routine): void
    {
        $this->guard($routine);
        $this->saved = null;
        $this->resetErrorBag();

        $form = $this->forms[$routine];
        $input = [
            'frequency' => $form['frequency'],
            'days' => $form['days'],
            'day_of_month' => $form['day_of_month'],
            'times' => $form['times'],
            'cron' => $form['cron'],
        ];

        try {
            $schedule = FlintScheduleSettings::validateSchedule($input);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError("forms.{$routine}.{$key}", $messages[0]);
            }

            return;
        }

        $user = Auth::user();
        $settings = $user->settings;
        $settings['flint']['schedules'][$routine] = $schedule;
        $user->settings = $settings;
        $user->save();

        $this->saved = $routine;
    }

    /**
     * Saves every routine this editor shows when the page's Save button is pressed.
     */
    #[On('save-flint-schedules')]
    public function saveAll(): void
    {
        foreach ($this->visibleRoutines() as $routine) {
            $this->save($routine);
        }
    }

    /** @return list<string> */
    private function visibleRoutines(): array
    {
        return $this->routine !== null ? [$this->routine] : FlintScheduleSettings::EDITABLE_ROUTINES;
    }

    public function render(): View
    {
        $user = Auth::user();
        $service = app(FlintScheduleService::class);
        $next = [];
        foreach ($this->visibleRoutines() as $routine) {
            $next[$routine] = $service->enabled($user, $routine)
                ? $service->nextEligibleRun($user, $routine)->format('D j M, H:i')
                : null;
        }

        return view('livewire.flint.schedule-editor', [
            'routines' => collect([
                'topics' => 'Topic review',
                'reading_list' => 'Reading list',
                'news_roundup' => 'News roundup',
                'money_weekly' => 'Money weekly check',
                'money_close' => 'Money monthly close',
            ])->only($this->visibleRoutines())->all(),
            'next' => $next,
        ]);
    }

    private function guard(string $routine): void
    {
        abort_unless(in_array($routine, FlintScheduleSettings::EDITABLE_ROUTINES, true), 404);
    }
}
