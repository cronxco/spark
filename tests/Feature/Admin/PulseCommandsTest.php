<?php

namespace Tests\Feature\Admin;

use App\Jobs\Admin\RunPulseCommand;
use App\Models\User;
use App\Support\PulseCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IsolatesLogs;
use Tests\TestCase;

/**
 * Admin audit PR 4: Pulse (formerly Sense Check) and its runnable data fixes.
 */
class PulseCommandsTest extends TestCase
{
    use IsolatesLogs;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $this->actingAs($this->admin);
    }

    #[Test]
    public function pulse_page_loads_and_old_urls_redirect(): void
    {
        $this->get(route('admin.pulse.index'))->assertOk()->assertSee('Pulse');
        $this->get(route('admin.sense-check.index', ['section' => 'money_data_health']))
            ->assertRedirect(route('admin.pulse.index', ['section' => 'money_data_health']));
        $this->get(route('admin.migrations.index'))->assertRedirect('/admin/pulse?section=data_fixes');
    }

    #[Test]
    public function every_registered_command_exists_in_artisan(): void
    {
        $artisan = array_keys(Artisan::all());

        foreach (array_keys(PulseCommands::all()) as $key) {
            if ($key === PulseCommands::OURA_JOB) {
                continue;
            }
            $this->assertContains($key, $artisan, "{$key} is not a registered artisan command");
        }
    }

    #[Test]
    public function preview_queues_a_job_for_the_viewed_tenant(): void
    {
        Queue::fake();

        Volt::test('admin.pulse-commands')->call('run', 'places:recalculate-visit-counts', 'preview');

        Queue::assertPushed(RunPulseCommand::class, fn ($job) => $job->key === 'places:recalculate-visit-counts'
            && $job->mode === 'preview'
            && $job->tenantId === (string) $this->admin->id);
    }

    #[Test]
    public function unknown_commands_and_missing_modes_are_refused(): void
    {
        Queue::fake();

        Volt::test('admin.pulse-commands')
            ->call('run', 'migrate:fresh', 'apply')
            ->call('run', 'oyster:backfill-transport-tags', 'preview')
            ->call('run', 'monzo:infer-interest', 'apply')
            ->call('run', 'receipts:repair-timezones', 'preview');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function sensitive_commands_need_the_command_name_typed_before_applying(): void
    {
        Queue::fake();

        $component = Volt::test('admin.pulse-commands')
            ->call('run', 'tokens:revoke-wildcard', 'apply');
        Queue::assertNothingPushed();

        $component->set('typed', ['tokens:revoke-wildcard' => 'tokens:revoke-wildcard'])
            ->call('run', 'tokens:revoke-wildcard', 'apply');
        Queue::assertPushed(RunPulseCommand::class, 1);
    }

    #[Test]
    public function a_running_command_is_not_queued_twice(): void
    {
        Queue::fake();

        $component = Volt::test('admin.pulse-commands')
            ->call('run', 'places:recalculate-visit-counts', 'preview')
            ->call('run', 'places:recalculate-visit-counts', 'preview');

        Queue::assertPushed(RunPulseCommand::class, 1);
    }

    #[Test]
    public function the_job_runs_the_command_and_keeps_its_output(): void
    {
        (new RunPulseCommand('places:recalculate-visit-counts', 'preview', (string) $this->admin->id, (string) $this->admin->id))->handle();

        $status = Cache::get(RunPulseCommand::cacheKey((string) $this->admin->id, 'places:recalculate-visit-counts'));

        $this->assertSame('done', $status['status'], $status['output']);
        $this->assertSame('preview', $status['mode']);
    }

    #[Test]
    public function the_job_ignores_modes_a_command_does_not_have(): void
    {
        (new RunPulseCommand('monzo:infer-interest', 'apply', (string) $this->admin->id, (string) $this->admin->id))->handle();

        $this->assertNull(Cache::get(RunPulseCommand::cacheKey((string) $this->admin->id, 'monzo:infer-interest')));
    }
}
