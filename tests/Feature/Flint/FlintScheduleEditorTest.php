<?php

namespace Tests\Feature\Flint;

use App\Livewire\Flint\ScheduleEditor;
use App\Models\User;
use App\Services\Flint\FlintScheduleService;
use App\Services\Flint\FlintScheduleSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlintScheduleEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ios.mobile_api_enabled' => true]);
    }

    #[Test]
    public function the_validator_normalises_a_valid_schedule(): void
    {
        $schedule = FlintScheduleSettings::validateSchedule([
            'frequency' => 'weekly', 'days' => ['mon', 'thu'], 'times' => ['18:00', '08:30'], 'cron' => ' 0 21 * * * ', 'day_of_month' => '', 'time' => null,
        ]);

        $this->assertEqualsCanonicalizing(['frequency' => 'weekly', 'days' => ['mon', 'thu'], 'times' => ['08:30', '18:00'], 'cron' => '0 21 * * *'], $schedule);
        $this->assertSame(['08:30', '18:00'], $schedule['times']);
    }

    #[Test]
    public function the_validator_rejects_malformed_input(): void
    {
        $cases = [
            'bad time' => [['frequency' => 'daily', 'times' => ['25:00']], 'times.0'],
            'text time' => [['frequency' => 'daily', 'times' => ['noon']], 'times.0'],
            'duplicate time' => [['frequency' => 'daily', 'times' => ['09:00', '09:00']], 'times.0'],
            'too many times' => [['frequency' => 'daily', 'times' => array_map(fn ($h) => sprintf('%02d:00', $h), range(0, 12))], 'times'],
            'not a cron' => [['cron' => 'every day'], 'cron'],
            'six fields' => [['cron' => '0 0 9 * * *'], 'cron'],
            'out of range cron' => [['cron' => '61 * * * *'], 'cron'],
            'every minute' => [['cron' => '* * * * *'], 'cron'],
            'every minute on mondays' => [['cron' => '* * * * 1'], 'cron'],
            'weekly without days' => [['frequency' => 'weekly'], 'days'],
            'unknown day' => [['frequency' => 'weekly', 'days' => ['funday']], 'days.0'],
            'monthly without day' => [['frequency' => 'monthly'], 'day_of_month'],
            'day 31' => [['frequency' => 'monthly', 'day_of_month' => 31], 'day_of_month'],
            'unknown frequency' => [['frequency' => 'hourly'], 'frequency'],
            'nothing at all' => [[], 'frequency'],
        ];

        foreach ($cases as $name => [$input, $field]) {
            try {
                FlintScheduleSettings::validateSchedule($input);
                $this->fail("{$name} was accepted");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors(), $name);
            }
        }
    }

    #[Test]
    public function a_saved_schedule_drives_the_occurrences(): void
    {
        $user = User::factory()->create(['settings' => ['timezone' => 'Europe/London']]);

        Livewire::actingAs($user)->test(ScheduleEditor::class)
            ->set('forms.topics.frequency', 'daily')
            ->set('forms.topics.times', ['08:00', '18:00'])
            ->call('save', 'topics')
            ->assertHasNoErrors()
            ->assertSet('saved', 'topics');

        $this->assertSame(['08:00', '18:00'], array_column(
            app(FlintScheduleService::class)->occurrencesOn($user->fresh(), 'topics', Carbon::parse('2026-10-13', 'Europe/London')),
            'key',
        ));
    }

    #[Test]
    public function a_cron_only_schedule_does_not_gain_an_unrequested_default_time(): void
    {
        $user = User::factory()->create(['settings' => [
            'timezone' => 'Europe/London',
            'flint' => ['schedules' => ['topics' => ['cron' => '30 7 * * 1-5']]],
        ]]);

        Livewire::actingAs($user)->test(ScheduleEditor::class)
            ->assertSet('forms.topics.times', [])
            ->call('save', 'topics')
            ->assertHasNoErrors();

        $saved = $user->fresh()->settings['flint']['schedules']['topics'];
        $this->assertSame('30 7 * * 1-5', $saved['cron']);
        $this->assertArrayNotHasKey('times', $saved);
    }

    #[Test]
    public function the_editor_shows_errors_and_stores_nothing_for_bad_input(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(ScheduleEditor::class)
            ->set('forms.topics.times', ['25:99'])
            ->call('save', 'topics')
            ->assertHasErrors(['forms.topics.times.0'])
            ->set('forms.topics.times', ['09:00'])
            ->set('forms.topics.cron', 'nonsense')
            ->call('save', 'topics')
            ->assertHasErrors(['forms.topics.cron'])
            ->set('forms.money_weekly.frequency', 'weekly')
            ->set('forms.money_weekly.days', [])
            ->call('save', 'money_weekly')
            ->assertHasErrors(['forms.money_weekly.days']);

        $this->assertArrayNotHasKey('schedules', $user->fresh()->settings['flint'] ?? []);
    }

    #[Test]
    public function times_can_be_added_and_removed(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(ScheduleEditor::class)
            ->call('addTime', 'topics')
            ->assertSet('forms.topics.times.1', '09:00')
            ->call('removeTime', 'topics', 0)
            ->assertCount('forms.topics.times', 1)
            ->set('forms.money_close.frequency', 'monthly')
            ->set('forms.money_close.day_of_month', '2')
            ->call('save', 'money_close')
            ->assertHasNoErrors();

        $settings = $user->fresh()->settings['flint'];
        $this->assertArrayNotHasKey('money_close_enabled', $settings);
        $this->assertSame(2, $settings['schedules']['money_close']['day_of_month']);
    }

    #[Test]
    public function the_flint_settings_page_hosts_the_editor(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('flint.index')->assertSeeLivewire(ScheduleEditor::class);
    }

    #[Test]
    public function the_page_save_saves_every_schedule_card(): void
    {
        $user = User::factory()->create(['settings' => ['timezone' => 'Europe/London']]);
        $this->actingAs($user);

        Livewire::test(ScheduleEditor::class, ['routine' => 'reading_list'])
            ->set('forms.reading_list.times', ['06:00'])
            ->dispatch('save-flint-schedules')
            ->assertHasNoErrors();

        $this->assertSame(['06:00'], $user->fresh()->settings['flint']['schedules']['reading_list']['times']);
        $this->assertArrayNotHasKey('topics', $user->fresh()->settings['flint']['schedules']);
    }

    #[Test]
    public function an_editor_for_one_routine_refuses_unknown_routines(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ScheduleEditor::class, ['routine' => 'morning_digest'])->assertStatus(404);
    }

    #[Test]
    public function saving_a_digest_or_unknown_routine_is_refused(): void
    {
        Livewire::actingAs(User::factory()->create())->test(ScheduleEditor::class)
            ->call('save', 'morning_digest')
            ->assertStatus(404);
    }

    #[Test]
    public function the_mobile_api_lists_and_updates_schedules_for_the_signed_in_user_only(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user, ['flint:read', 'flint:write']);

        $this->getJson('/api/v1/mobile/flint/schedules')->assertOk()->assertJsonCount(5, 'data');

        $this->putJson('/api/v1/mobile/flint/schedules/topics', [
            'enabled' => true, 'frequency' => 'daily', 'times' => ['07:00', '19:00'], 'cron' => null,
        ])->assertOk()->assertJsonPath('data.schedule.times', ['07:00', '19:00'])->assertJsonPath('data.enabled', true);

        $this->assertSame(['07:00', '19:00'], $user->fresh()->settings['flint']['schedules']['topics']['times']);
        $this->assertArrayNotHasKey('flint', $other->fresh()->settings ?? []);
    }

    #[Test]
    public function the_mobile_api_rejects_bad_schedules_and_unknown_routines(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['flint:read', 'flint:write']);

        $this->putJson('/api/v1/mobile/flint/schedules/topics', ['cron' => 'bad'])->assertStatus(422)->assertJsonValidationErrors('cron');
        $this->putJson('/api/v1/mobile/flint/schedules/topics', ['frequency' => 'daily', 'times' => ['9am']])->assertStatus(422);
        $this->putJson('/api/v1/mobile/flint/schedules/morning_digest', ['frequency' => 'daily'])->assertNotFound();
        $this->assertArrayNotHasKey('schedules', $user->fresh()->settings['flint'] ?? []);
    }

    #[Test]
    public function the_mobile_api_needs_the_right_abilities(): void
    {
        $this->getJson('/api/v1/mobile/flint/schedules')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(), ['flint:read']);
        $this->putJson('/api/v1/mobile/flint/schedules/topics', ['frequency' => 'daily'])->assertForbidden();
    }
}
