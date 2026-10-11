<?php

namespace Tests\Feature\Money;

use App\Integrations\Monzo\MonzoPlugin;
use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MonzoPotDetailsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $integration;

    private MonzoPlugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.enable_task_pipeline' => false]);
        $this->user = User::factory()->create();
        $group = IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => 'monzo']);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $group->id,
            'service' => 'monzo',
            'instance_type' => 'pots',
        ]);
        $this->plugin = new MonzoPlugin;
    }

    #[Test]
    public function pot_details_from_the_api_are_kept_on_create_and_update(): void
    {
        $pot = $this->apiPot(['goal_amount' => 500000, 'current_account_id' => 'acc_joint', 'round_up' => false, 'locked' => null]);

        $created = $this->plugin->upsertPotObject($this->integration, $pot);

        $this->assertSame(500000, $created->metadata['monzo']['goal_amount']);
        $this->assertSame('acc_joint', $created->metadata['monzo']['current_account_id']);
        $this->assertFalse($created->metadata['monzo']['round_up']);
        $this->assertArrayNotHasKey('locked', $created->metadata['monzo']);

        $updated = $this->plugin->upsertPotObject($this->integration, $this->apiPot(['goal_amount' => 750000]));

        $this->assertSame((string) $created->id, (string) $updated->id);
        $this->assertSame(750000, $updated->fresh()->metadata['monzo']['goal_amount']);
    }

    #[Test]
    public function spark_owned_pot_settings_survive_a_sync(): void
    {
        $pot = $this->plugin->upsertPotObject($this->integration, $this->apiPot(['goal_amount' => 1000]));
        $pot->update(['metadata' => array_merge($pot->metadata, ['money' => ['role' => 'card_buffer']])]);

        $synced = $this->plugin->upsertPotObject($this->integration, $this->apiPot(['goal_amount' => 2000]));

        $this->assertSame('card_buffer', $synced->fresh()->metadata['money']['role']);
    }

    #[Test]
    public function a_sync_without_details_does_not_wipe_stored_ones(): void
    {
        $this->plugin->upsertPotObject($this->integration, $this->apiPot(['goal_amount' => 1000]));

        $synced = $this->plugin->upsertPotObject($this->integration, ['id' => 'pot_1', 'name' => 'Holiday', 'balance' => 5]);

        $this->assertSame(1000, $synced->fresh()->metadata['monzo']['goal_amount']);
    }

    #[Test]
    public function transactions_in_a_named_custom_category_are_tagged_with_its_name(): void
    {
        $master = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->integration->integration_group_id,
            'service' => 'monzo',
            'instance_type' => 'accounts',
            'configuration' => ['category_names' => ['category_abc123' => 'Lunch']],
        ]);

        $this->plugin->processTransactionItem($this->integration, $this->transaction('tx_1', 'category_abc123'), 'acc_1');
        $this->plugin->processTransactionItem($this->integration, $this->transaction('tx_2', 'category_unnamed'), 'acc_1');

        $named = Event::where('source_id', 'tx_1')->firstOrFail();
        $unnamed = Event::where('source_id', 'tx_2')->firstOrFail();
        $this->assertSame(['Lunch'], $named->tagsWithType('transaction_category')->pluck('name')->all());
        $this->assertSame(['category_unnamed'], $unnamed->tagsWithType('transaction_category')->pluck('name')->all());
        $this->assertSame('category_abc123', $named->event_metadata['category']);
        $this->assertNotNull($master);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function apiPot(array $extra = []): array
    {
        return array_merge(['id' => 'pot_1', 'name' => 'Holiday', 'balance' => 12345, 'deleted' => false], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function transaction(string $id, string $category): array
    {
        return [
            'id' => $id, 'amount' => -900, 'currency' => 'GBP', 'scheme' => 'mastercard', 'category' => $category,
            'created' => '2026-02-01T12:00:00Z', 'description' => 'LEON', 'settled' => '2026-02-01T12:00:00Z',
            'merchant' => ['id' => 'merch_1', 'name' => 'Leon'],
        ];
    }
}
