<?php

namespace App\Livewire\Flint;

use App\Services\Flint\FlintScheduleService;
use App\Services\Flint\FlintScheduleSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Edits when each repeating Flint routine runs: one or more local times, which days, and an optional
 * advanced cron expression. Everything is checked by {@see FlintScheduleSettings::validateSchedule()}
 * before it is stored, so a bad value is rejected here instead of silently falling back to a default.
 */
class ScheduleEditor extends Component
{
    /** @var array<string, array<string, mixed>> */
    public array $forms = [];

    public ?string $saved = null;

    public function mount(): void
    {
        $settings = Auth::user()->settings['flint'] ?? [];
        $service = app(FlintScheduleService::class);

        foreach (FlintScheduleSettings::EDITABLE_ROUTINES as $routine) {
            $cadence = $service->cadence(Auth::user(), $routine) ?? [];
            $this->forms[$routine] = [
                'enabled' => FlintScheduleSettings::enabled($settings, "{$routine}_enabled"),
                'frequency' => $cadence['frequency'] ?? 'daily',
                'days' => $cadence['days'] ?? [],
                'day_of_month' => (string) ($cadence['day_of_month'] ?? '1'),
                'times' => $cadence['times'] ?? [$service->slot(Auth::user(), $routine)],
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
        if ($this->ownsSwitch($routine)) {
            $settings['flint']["{$routine}_enabled"] = (bool) $form['enabled'];
        }
        $settings['flint']['schedules'][$routine] = $schedule;
        $user->settings = $settings;
        $user->save();

        $this->saved = $routine;
    }

    public function render(): View
    {
        $user = Auth::user();
        $service = app(FlintScheduleService::class);
        $next = [];
        foreach (FlintScheduleSettings::EDITABLE_ROUTINES as $routine) {
            $next[$routine] = $service->enabled($user, $routine)
                ? $service->nextEligibleRun($user, $routine)->format('D j M, H:i')
                : null;
        }

        return view('livewire.flint.schedule-editor', [
            'routines' => [
                'topics' => 'Topic review',
                'reading_list' => 'Reading list',
                'news_roundup' => 'News roundup',
                'money_weekly' => 'Money weekly check',
                'money_close' => 'Money monthly close',
            ],
            'next' => $next,
        ]);
    }

    /**
     * The money routines have no switch elsewhere on the Flint page, so this editor owns theirs.
     */
    public function ownsSwitch(string $routine): bool
    {
        return str_starts_with($routine, 'money_');
    }

    private function guard(string $routine): void
    {
        abort_unless(in_array($routine, FlintScheduleSettings::EDITABLE_ROUTINES, true), 404);
    }
}
