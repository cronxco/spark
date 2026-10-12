<?php

namespace Tests\Feature\Money;

use App\Integrations\Monzo\MonzoPlugin;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MonzoSalaryRejectTest extends TestCase
{
    use RefreshDatabase;

    private MonzoPlugin $plugin;

    private User $user;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.monzo.salary_name' => 'Smb1']);
        $this->plugin = new MonzoPlugin;
        $this->user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => 'monzo']);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
            'instance_type' => 'transactions',
        ]);
    }

    #[Test]
    public function rejected_salary_stays_a_normal_credit_on_resync(): void
    {
        $this->plugin->processTransactionItem($this->integration, $this->salaryTx(), 'acc_1');
        $event = Event::where('source_id', 'tx_salary')->firstOrFail();
        $this->assertSame('salary_received_from', $event->action);

        MonzoPlugin::rejectSalary($event);
        $this->assertSame('direct_credit_from', $event->fresh()->action);

        $this->plugin->processTransactionItem($this->integration, $this->salaryTx(), 'acc_1');
        $event = $event->fresh();
        $this->assertSame('direct_credit_from', $event->action);
        $this->assertTrue($event->event_metadata['salary_rejected']);
        $this->assertSame('bacs', $event->event_metadata['scheme']);
    }

    #[Test]
    public function event_page_rejects_salary(): void
    {
        $this->plugin->processTransactionItem($this->integration, $this->salaryTx(), 'acc_1');
        $event = Event::where('source_id', 'tx_salary')->firstOrFail();

        $this->actingAs($this->user);
        Volt::test('events.show', ['event' => $event])->call('rejectSalary');

        $this->assertSame('direct_credit_from', $event->fresh()->action);
    }

    private function salaryTx(): array
    {
        return [
            'id' => 'tx_salary',
            'created' => '2026-10-12T18:25:00Z',
            'amount' => 749623,
            'currency' => 'GBP',
            'scheme' => 'bacs',
            'settled' => '2026-10-12T18:25:00Z',
            'merchant' => ['id' => 'm_1', 'name' => 'Smb1'],
        ];
    }
}
