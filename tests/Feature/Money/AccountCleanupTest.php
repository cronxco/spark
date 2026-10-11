<?php

namespace Tests\Feature\Money;

use App\Jobs\Money\ApplyAccountCleanup;
use App\Jobs\OAuth\Monzo\MonzoPotPull;
use App\Models\ActionProgress;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\Money\AccountCleanup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Tags\Tag;
use Tests\TestCase;

class AccountCleanupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Integration $gocardless;

    private Integration $monzo;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.enable_task_pipeline' => false]);
        $this->user = User::factory()->create();
        $this->gocardless = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => 'gocardless'])->id,
            'service' => 'gocardless',
            'instance_type' => 'accounts',
        ]);
        $this->monzo = Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => IntegrationGroup::factory()->create(['user_id' => $this->user->id, 'service' => 'monzo'])->id,
            'service' => 'monzo',
            'instance_type' => 'accounts',
            'configuration' => [],
        ]);
    }

    #[Test]
    public function renewed_consent_duplicates_with_a_new_resource_id_are_merged_with_their_history(): void
    {
        $identity = ['integration_id' => $this->gocardless->id, 'provider' => 'American Express', 'currency' => 'GBP', 'account_number' => '1002'];
        $older = $this->bankAccount('Premium Plus', 'acc-1', $identity + ['raw' => ['resourceId' => 'resource-b']], '2025-10-17');
        $newer = $this->bankAccount('American Express P', 'acc-1', $identity + ['raw' => ['resourceId' => 'resource-a']], '2026-01-12');
        $event = $this->event($newer, 'payment_to', 1200);

        $plan = $this->cleanup()->plan($this->user->id, AccountCleanup::GOCARDLESS_KINDS);

        $this->assertCount(1, $plan['steps']);
        $this->assertSame(AccountCleanup::KIND_MERGE, $plan['steps'][0]['kind']);
        $this->assertNull($plan['steps'][0]['blocked']);
        $this->assertSame($older->id, $plan['steps'][0]['payload']['canonical_id']);

        $this->cleanup()->apply($this->user->id, $plan['fingerprint']);

        $this->assertSame($older->id, $event->fresh()->actor_id);
        $this->assertTrue($newer->fresh()->trashed());
        $this->assertFalse($older->fresh()->trashed());
        $this->assertSame($older->id, EventObject::withTrashed()->find($newer->id)->metadata['merged_into']);
    }

    #[Test]
    public function a_new_resource_id_without_a_verified_provider_identity_blocks_the_merge(): void
    {
        $this->bankAccount('Premium Plus', 'acc-1', ['raw' => ['resourceId' => 'resource-b']], '2025-10-17');
        $this->bankAccount('American Express P', 'acc-1', ['raw' => ['resourceId' => 'resource-a']], '2026-01-12');

        $plan = $this->cleanup()->plan($this->user->id, AccountCleanup::GOCARDLESS_KINDS);

        $this->assertStringContainsString('Conflicting bank identity', $plan['steps'][0]['blocked']);
    }

    #[Test]
    public function a_different_iban_blocks_the_merge(): void
    {
        $this->bankAccount('First', 'acc-1', ['raw' => ['iban' => 'GB01AAAA']], '2025-10-17');
        $this->bankAccount('Second', 'acc-1', ['raw' => ['iban' => 'GB02BBBB']], '2026-01-12');

        $step = $this->cleanup()->plan($this->user->id)['steps'][0];

        $this->assertNotNull($step['blocked']);
        $this->assertStringContainsString('bank identity', $step['blocked']);
    }

    #[Test]
    public function an_unidentified_account_is_merged_into_the_one_with_the_same_bank_identity(): void
    {
        $identified = $this->bankAccount('GBP [uuid]', 'acc-9', ['raw' => ['resourceId' => '1886342', 'iban' => 'GB35TRWI']], '2025-10-18');
        $unknown = $this->bankAccount('GBP', 'unknown', ['raw' => ['resourceId' => '1886342', 'iban' => 'GB35TRWI']], '2025-10-18');
        $event = $this->event($unknown, 'had_balance', 500);

        $plan = $this->cleanup()->plan($this->user->id);
        $kinds = array_column($plan['steps'], 'kind');

        $this->assertContains(AccountCleanup::KIND_MERGE, $kinds);
        $this->assertNotContains(AccountCleanup::KIND_QUARANTINE, $kinds);

        $this->cleanup()->apply($this->user->id, $plan['fingerprint']);

        $this->assertSame($identified->id, $event->fresh()->actor_id);
        $this->assertNotContains('unknown', $identified->fresh()->metadata['gocardless_account_ids'] ?? []);
    }

    #[Test]
    public function an_unidentified_account_with_no_match_is_set_aside_once(): void
    {
        $this->bankAccount('Mystery', 'unknown', ['raw' => ['resourceId' => 'nobody']], '2025-10-18');

        $first = $this->cleanup()->plan($this->user->id);
        $this->assertSame(AccountCleanup::KIND_QUARANTINE, $first['steps'][0]['kind']);

        $this->cleanup()->apply($this->user->id, $first['fingerprint']);

        $this->assertSame([], $this->cleanup()->plan($this->user->id)['steps']);
    }

    #[Test]
    public function placeholder_names_with_an_account_id_are_cleaned_unless_the_name_is_taken(): void
    {
        $uuid = '7999f00b-0fdd-4bf3-be11-7f727e6dae14';
        $placeholder = $this->bankAccount("GBP [{$uuid}]", 'acc-1', [], '2025-10-18');
        $this->bankAccount('EUR [c997de41-4b61-4c7d-8082-2c5e4669a787]', 'acc-2', [], '2025-10-18');
        $this->bankAccount('EUR', 'acc-3', [], '2025-10-18');

        $steps = collect($this->cleanup()->plan($this->user->id)['steps'])->keyBy(fn ($step) => $step['payload']['title']);

        $this->assertNull($steps['GBP']['blocked']);
        $this->assertNotNull($steps['EUR']['blocked']);

        $plan = $this->cleanup()->plan($this->user->id);
        $this->cleanup()->apply($this->user->id, $plan['fingerprint']);

        $this->assertSame('GBP', $placeholder->fresh()->title);
    }

    #[Test]
    public function applying_a_stale_plan_is_refused(): void
    {
        $plan = $this->cleanup()->plan($this->user->id);
        $this->bankAccount('First', 'acc-1', [], '2025-10-17');
        $this->bankAccount('Second', 'acc-1', [], '2026-01-12');

        $this->expectException(RuntimeException::class);
        $this->cleanup()->apply($this->user->id, $plan['fingerprint']);
    }

    #[Test]
    public function archived_pots_keep_their_history_and_one_closing_zero(): void
    {
        $pot = $this->pot('Honeymoon', 'monzo_archived_pot');
        foreach ([500, 200, 0, 0, 0] as $day => $value) {
            $this->event($pot, 'had_balance', $value, sprintf('2026-01-%02d', $day + 1));
        }
        $allZero = $this->pot('Coinjar', 'monzo_archived_pot');
        foreach ([0, 0, 0] as $day => $value) {
            $this->event($allZero, 'had_balance', $value, sprintf('2026-01-%02d', $day + 1));
        }
        $active = $this->pot('Holiday', 'monzo_pot');
        foreach ([0, 0, 0] as $day => $value) {
            $this->event($active, 'had_balance', $value, sprintf('2026-01-%02d', $day + 1));
        }

        $plan = $this->cleanup()->plan($this->user->id, [AccountCleanup::KIND_SNAPSHOTS]);

        $this->assertSame(4, $plan['steps'][0]['payload']['rows']);

        $this->cleanup()->apply($this->user->id, $plan['fingerprint'], [AccountCleanup::KIND_SNAPSHOTS]);

        $this->assertSame(3, Event::where('actor_id', $pot->id)->count());
        $this->assertSame(1, Event::where('actor_id', $allZero->id)->count());
        $this->assertSame(3, Event::where('actor_id', $active->id)->count());
        $this->assertSame(2, Event::onlyTrashed()->where('actor_id', $pot->id)->count());
        $this->assertSame(2, $pot->fresh()->metadata['snapshot_cleanup']['removed']);
        $this->assertSame([], $this->cleanup()->plan($this->user->id, [AccountCleanup::KIND_SNAPSHOTS])['steps']);
    }

    #[Test]
    public function snapshot_cleanup_never_removes_non_zero_or_negative_balances(): void
    {
        $pot = $this->pot('Old pot', 'monzo_archived_pot');
        foreach ([100, 0, 0, 50, 0, 0, 0, -20, 0, 0] as $day => $value) {
            $this->event($pot, 'had_balance', $value, sprintf('2026-01-%02d', $day + 1));
        }

        $plan = $this->cleanup()->plan($this->user->id, [AccountCleanup::KIND_SNAPSHOTS]);
        $this->assertSame(1, $plan['steps'][0]['payload']['rows']);

        $this->cleanup()->apply($this->user->id, $plan['fingerprint'], [AccountCleanup::KIND_SNAPSHOTS]);

        $this->assertSame(9, Event::where('actor_id', $pot->id)->count());
        $this->assertSame(1, Event::where('actor_id', $pot->id)->where('value', -20)->count());
        $this->assertSame(1, Event::onlyTrashed()->where('actor_id', $pot->id)->count());
    }

    #[Test]
    public function monzo_custom_categories_need_a_name_and_are_then_renamed_into_the_existing_tag(): void
    {
        $account = $this->pot('Current', 'monzo_account');
        $lunch = $this->event($account, 'card_payment_to', 900, '2026-02-01', ['category' => 'category_abc123', 'raw' => ['merchant' => ['name' => 'Leon']]]);
        $other = $this->event($account, 'card_payment_to', 400, '2026-02-02', ['category' => 'category_abc123', 'raw' => ['merchant' => ['name' => 'Pret']]]);
        foreach ([$lunch, $other] as $event) {
            $event->attachTag('category_abc123', 'transaction_category');
        }
        $other->attachTag('Lunch', 'transaction_category');

        $first = $this->cleanup()->plan($this->user->id, [AccountCleanup::KIND_CATEGORY]);
        $this->assertTrue($first['steps'][0]['needs_input']);
        $this->assertStringContainsString('Leon', $first['steps'][0]['detail']);

        $this->cleanup()->setCategoryName($this->user->id, 'category_abc123', 'Lunch');
        $this->assertSame(['category_abc123' => 'Lunch'], $this->cleanup()->categoryNames($this->user->id));

        $plan = $this->cleanup()->plan($this->user->id, [AccountCleanup::KIND_CATEGORY]);
        $this->assertFalse($plan['steps'][0]['needs_input']);
        $this->cleanup()->apply($this->user->id, $plan['fingerprint'], [AccountCleanup::KIND_CATEGORY]);

        $this->assertNull(Tag::findFromString('category_abc123', 'transaction_category'));
        $this->assertSame(['Lunch'], $lunch->fresh()->tagsWithType('transaction_category')->pluck('name')->all());
        $this->assertSame(['Lunch'], $other->fresh()->tagsWithType('transaction_category')->pluck('name')->all());
        $this->assertSame([], $this->cleanup()->plan($this->user->id, [AccountCleanup::KIND_CATEGORY])['steps']);
    }

    #[Test]
    public function renaming_a_category_leaves_other_users_tag_associations_alone(): void
    {
        $account = $this->pot('Current', 'monzo_account');
        $mine = $this->event($account, 'card_payment_to', 900, '2026-02-01', ['category' => 'category_abc123', 'raw' => ['merchant' => ['name' => 'Leon']]]);
        $mine->attachTag('category_abc123', 'transaction_category');

        $other = User::factory()->create();
        $otherIntegration = Integration::factory()->create([
            'user_id' => $other->id,
            'integration_group_id' => IntegrationGroup::factory()->create(['user_id' => $other->id, 'service' => 'monzo'])->id,
            'service' => 'monzo',
            'instance_type' => 'accounts',
        ]);
        $otherActor = EventObject::create(['user_id' => $other->id, 'concept' => 'account', 'type' => 'monzo_account', 'title' => 'Theirs', 'metadata' => []]);
        $theirs = Event::create([
            'integration_id' => $otherIntegration->id, 'source_id' => 'other-1', 'actor_id' => $otherActor->id,
            'target_id' => $otherActor->id, 'service' => 'monzo', 'domain' => 'money', 'action' => 'card_payment_to',
            'time' => '2026-02-01 12:00:00', 'value' => 100, 'value_multiplier' => 100, 'value_unit' => 'GBP',
        ]);
        $theirs->attachTag('category_abc123', 'transaction_category');

        $this->cleanup()->setCategoryName($this->user->id, 'category_abc123', 'Lunch');
        $plan = $this->cleanup()->plan($this->user->id, [AccountCleanup::KIND_CATEGORY]);
        $this->cleanup()->apply($this->user->id, $plan['fingerprint'], [AccountCleanup::KIND_CATEGORY]);

        $this->assertSame(['Lunch'], $mine->fresh()->tagsWithType('transaction_category')->pluck('name')->all());
        $this->assertSame(['category_abc123'], $theirs->fresh()->tagsWithType('transaction_category')->pluck('name')->all());
        $this->assertNotNull(Tag::findFromString('category_abc123', 'transaction_category'));
    }

    #[Test]
    public function two_placeholder_names_resolving_to_the_same_title_block_the_second_rename(): void
    {
        $this->bankAccount('Current [11111111-1111-1111-1111-111111111111]', 'acc-1', [], '2026-01-01');
        $this->bankAccount('Current [22222222-2222-2222-2222-222222222222]', 'acc-2', [], '2026-01-02');

        $plan = $this->cleanup()->plan($this->user->id, [AccountCleanup::KIND_RENAME]);

        $this->assertCount(2, $plan['steps']);
        $this->assertNull($plan['steps'][0]['blocked']);
        $this->assertNotNull($plan['steps'][1]['blocked']);
    }

    #[Test]
    public function pots_missing_monzo_details_queue_a_read_only_refresh(): void
    {
        Queue::fake();
        $this->pot('Holiday', 'monzo_pot');
        Integration::factory()->create([
            'user_id' => $this->user->id,
            'integration_group_id' => $this->monzo->integration_group_id,
            'service' => 'monzo',
            'instance_type' => 'pots',
        ]);

        $plan = $this->cleanup()->plan($this->user->id, [AccountCleanup::KIND_POT_DETAILS]);
        $this->assertNull($plan['steps'][0]['blocked']);

        $this->cleanup()->apply($this->user->id, $plan['fingerprint'], [AccountCleanup::KIND_POT_DETAILS]);

        Queue::assertPushed(MonzoPotPull::class);
    }

    #[Test]
    public function the_job_applies_the_plan_and_reports_progress(): void
    {
        $this->bankAccount('First', 'acc-1', [], '2025-10-17');
        $duplicate = $this->bankAccount('Second', 'acc-1', [], '2026-01-12');
        $plan = $this->cleanup()->plan($this->user->id);
        $progress = ActionProgress::createProgress($this->user->id, ApplyAccountCleanup::ACTION_TYPE, 'run-1', 'queued', 'Waiting', 0);

        (new ApplyAccountCleanup($this->user->id, $plan['fingerprint'], (string) $progress->id))->handle($this->cleanup());

        $this->assertTrue($duplicate->fresh()->trashed());
        $this->assertTrue($progress->fresh()->isCompleted());
        $this->assertSame(1, $progress->fresh()->details['applied']);
    }

    #[Test]
    public function the_job_marks_progress_failed_when_the_plan_went_stale(): void
    {
        $progress = ActionProgress::createProgress($this->user->id, ApplyAccountCleanup::ACTION_TYPE, 'run-2', 'queued', 'Waiting', 0);
        $this->bankAccount('First', 'acc-1', [], '2025-10-17');
        $this->bankAccount('Second', 'acc-1', [], '2026-01-12');

        (new ApplyAccountCleanup($this->user->id, 'not-the-plan', (string) $progress->id))->handle($this->cleanup());

        $this->assertTrue($progress->fresh()->isFailed());
    }

    #[Test]
    public function other_users_data_is_never_planned(): void
    {
        $stranger = User::factory()->create();
        EventObject::create(['user_id' => $stranger->id, 'concept' => 'account', 'type' => 'bank_account',
            'title' => 'X [7999f00b-0fdd-4bf3-be11-7f727e6dae14]', 'metadata' => ['account_id' => 'a']]);

        $this->assertSame([], $this->cleanup()->plan($this->user->id)['steps']);
    }

    private function cleanup(): AccountCleanup
    {
        return app(AccountCleanup::class);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function bankAccount(string $title, string $accountId, array $extra, string $createdAt): EventObject
    {
        $object = EventObject::create([
            'user_id' => $this->user->id, 'concept' => 'account', 'type' => 'bank_account', 'title' => $title,
            'metadata' => array_merge(['account_id' => $accountId, 'name' => $title, 'currency' => 'GBP'], $extra),
        ]);
        $object->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $object->refresh();
    }

    private function pot(string $title, string $type): EventObject
    {
        return EventObject::create([
            'user_id' => $this->user->id, 'concept' => 'account', 'type' => $type, 'title' => $title,
            'metadata' => ['name' => $title, 'pot_id' => 'pot_'.$title],
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function event(EventObject $actor, string $action, int $value, string $day = '2026-01-01', array $metadata = []): Event
    {
        static $counter = 0;
        $integration = $actor->type === 'bank_account' ? $this->gocardless : $this->monzo;
        $target = EventObject::firstOrCreate(
            ['user_id' => $this->user->id, 'concept' => 'day', 'type' => 'day', 'title' => $day],
            ['time' => $day.' 00:00:00', 'metadata' => []],
        );

        return Event::create([
            'integration_id' => $integration->id, 'source_id' => 'evt-'.(++$counter), 'actor_id' => $actor->id,
            'target_id' => $target->id, 'service' => $integration->service, 'domain' => 'money', 'action' => $action,
            'time' => $day.' 23:59:59', 'value' => $value, 'value_multiplier' => 100, 'value_unit' => 'GBP',
            'event_metadata' => $metadata,
        ]);
    }
}
