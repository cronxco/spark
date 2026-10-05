<?php

namespace Tests\Feature;

use App\Integrations\Financial\FinancialPlugin;
use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Jobs\Data\GoCardless\GoCardlessAccountData;
use App\Jobs\GoCardless\HandleExpiredEuaJob;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\Relationship;
use App\Models\User;
use App\Services\GoCardlessAccounts;
use App\Services\Mobile\ObjectLookup;
use App\Services\TaskPipeline\TaskExecutionStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class GoCardlessAccountRenewalTest extends TestCase
{
    private User $owner;

    private IntegrationGroup $group;

    private Integration $integration;

    private GoCardlessBankPlugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.enable_task_pipeline' => false]);
        Queue::fake();
        Cache::flush();
        Http::preventStrayRequests();
        $this->owner = User::factory()->create();
        $this->group = IntegrationGroup::factory()->create([
            'user_id' => $this->owner->id, 'service' => 'gocardless', 'account_id' => 'old-requisition',
            'auth_metadata' => ['institution_id' => 'test-bank', 'eua_expired' => true],
        ]);
        $this->integration = Integration::factory()->create([
            'user_id' => $this->owner->id, 'integration_group_id' => $this->group->id,
            'service' => 'gocardless', 'instance_type' => 'accounts',
            'configuration' => ['account_id' => 'old-account', 'paused' => true, 'gocardless_pause_reason' => 'eua_expired'],
        ]);
        $this->plugin = new class extends GoCardlessBankPlugin
        {
            public function getAccessToken(): string
            {
                return 'test-token';
            }
        };
    }

    #[Test]
    public function repeated_sync_preserves_custom_name_settings_and_identity(): void
    {
        $original = $this->account();
        $result = $this->plugin->upsertAccountObject($this->integration, $this->details());
        $again = $this->plugin->upsertAccountObject($this->integration, $this->details());
        $this->assertSame($original->id, $result->id);
        $this->assertSame($original->id, $again->id);
        $this->assertSame('My card', $result->title);
        $this->assertTrue($result->metadata['is_pinned']);
        $this->assertTrue($result->metadata['is_negative_balance']);
        $this->assertSame($original->id, $this->integration->fresh()->configuration['account_object_id']);
    }

    #[Test]
    public function identical_names_do_not_merge_different_accounts(): void
    {
        $first = $this->plugin->upsertAccountObject($this->integration, $this->details());
        $other = $this->plugin->createInstance($this->group, 'accounts', ['account_id' => 'other-account']);
        $second = $this->plugin->upsertAccountObject($other, $this->details('other-account', 'other-resource'));
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($first->title, $second->title);
    }

    #[Test]
    public function unidentified_payload_cannot_contaminate_an_existing_unknown_object(): void
    {
        $unknown = $this->account('Unidentified', ['account_id' => 'unknown']);
        try {
            $this->plugin->upsertAccountObject($this->integration, ['id' => 'unknown', 'details' => 'Other bank']);
            $this->fail('Unknown must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame('Unidentified', $unknown->fresh()->title);
        }
    }

    #[Test]
    public function both_cache_read_paths_return_the_same_identity(): void
    {
        Cache::put('gocardless_account_details_v2_old-account', ['account' => ['ownerName' => 'Owner']], 60);
        Cache::put('gocardless_group_accounts_v2_' . $this->group->id . '_old-requisition', ['old-account'], 60);
        $this->assertSame('old-account', $this->plugin->getAccount('old-account')['id']);
        $this->assertSame('old-account', $this->plugin->pullAccountData($this->integration)['id']);
        $object = $this->plugin->upsertAccountObject($this->integration, $this->plugin->pullAccountData($this->integration));
        $this->assertSame('old-account', $object->metadata['account_id']);
        Http::assertNothingSent();
    }

    #[Test]
    public function onboarding_is_read_only_and_instance_creation_is_idempotent(): void
    {
        Cache::put('gocardless_group_accounts_v2_' . $this->group->id . '_old-requisition', ['old-account'], 60);
        Cache::put('gocardless_account_details_v2_old-account', $this->details(), 60);
        $this->assertCount(1, $this->plugin->getAvailableAccountsForOnboarding($this->group));
        $this->assertSame(0, EventObject::where('user_id', $this->owner->id)->count());
        $again = $this->plugin->createInstance($this->group, 'accounts', ['account_id' => 'old-account']);
        $this->assertSame($this->integration->id, $again->id);
    }

    #[Test]
    public function beginning_renewal_preserves_active_consent_and_expiry_and_reuses_pending_attempt(): void
    {
        Http::fake([
            '*/agreements/enduser/' => Http::response(['id' => 'agreement']),
            '*/requisitions/' => Http::response(['id' => 'pending', 'reference' => 'random-reference', 'link' => 'https://example.test/consent']),
        ]);
        $url = $this->plugin->getOAuthUrl($this->group);
        $this->assertSame($url, $this->plugin->getOAuthUrl($this->group));
        $this->assertSame('old-requisition', $this->group->fresh()->account_id);
        $this->assertTrue($this->group->fresh()->auth_metadata['eua_expired']);
        Http::assertSentCount(2);
    }

    #[Test]
    public function renewal_reconciles_changed_ids_and_preserves_manual_pause(): void
    {
        $original = $this->account();
        $manual = $this->plugin->createInstance($this->group, 'balances', ['account_id' => 'old-account', 'paused' => true, 'gocardless_pause_reason' => 'user']);
        $oldSnapshot = GoCardlessAccounts::snapshot($this->integration);
        $this->stage();
        $this->provider($this->details('new-account'));
        $this->assertTrue($this->plugin->completeRenewal($this->group, 'pending-reference'));
        $this->assertSame('new-requisition', $this->group->fresh()->account_id);
        $this->assertSame('new-account', $this->integration->fresh()->configuration['account_id']);
        $this->assertFalse($this->integration->fresh()->configuration['paused']);
        $this->assertTrue($manual->fresh()->configuration['paused']);
        $this->assertSame($original->id, app(GoCardlessAccounts::class)->find($this->owner->id, 'new-account')->id);
        $this->assertContains('old-account', $original->fresh()->metadata['gocardless_account_ids']);
        $this->assertFalse(GoCardlessAccounts::isCurrent($this->integration, $oldSnapshot));
        $this->assertFalse(GoCardlessAccounts::isCurrent($this->integration, null));
        $this->assertTrue($this->plugin->completeRenewal($this->group, 'pending-reference'));
        Http::assertSentCount(2);
    }

    #[Test]
    public function ambiguous_identity_requires_explicit_mapping_without_changing_active_connection(): void
    {
        $original = $this->account();
        $this->stage();
        $this->provider($this->details('new-account', 'different-bank-resource'));
        $this->assertFalse($this->plugin->completeRenewal($this->group, 'pending-reference'));
        $this->assertSame('old-requisition', $this->group->fresh()->account_id);
        $this->assertTrue($this->group->fresh()->auth_metadata['gocardless_pending']['requires_mapping']);
        $this->assertTrue($this->plugin->completeRenewal($this->group, 'pending-reference', ['old-account' => 'new-account']));
        $this->assertSame($original->id, app(GoCardlessAccounts::class)->find($this->owner->id, 'new-account')->id);
    }

    #[Test]
    public function unfinished_consent_does_not_clear_expiry_or_resume_jobs(): void
    {
        $this->stage();
        $this->provider($this->details(), 'CR');
        try {
            $this->plugin->completeRenewal($this->group, 'pending-reference');
            $this->fail('Consent must be linked.');
        } catch (RuntimeException) {
            $this->assertSame('old-requisition', $this->group->fresh()->account_id);
            $this->assertTrue($this->group->fresh()->auth_metadata['eua_expired']);
            $this->assertTrue($this->integration->fresh()->configuration['paused']);
        }
    }

    #[Test]
    public function obsolete_expiry_job_does_not_expire_a_new_generation(): void
    {
        $snapshot = GoCardlessAccounts::snapshot($this->integration);
        $this->group->update(['account_id' => 'new-requisition', 'auth_metadata' => ['gocardless_generation' => 'new', 'eua_expired' => false]]);
        (new HandleExpiredEuaJob($this->group->id, null, [], $snapshot))->handle(app(TaskExecutionStore::class));
        $this->assertFalse($this->group->fresh()->auth_metadata['eua_expired']);
    }

    #[Test]
    public function obsolete_processing_job_cannot_overwrite_account_metadata(): void
    {
        $original = $this->account();
        $job = new GoCardlessAccountData($this->integration, $this->details());
        $this->group->update(['account_id' => 'new-requisition', 'auth_metadata' => ['gocardless_generation' => 'new', 'eua_expired' => false]]);
        $job->handle();
        $this->assertSame('My card', $original->fresh()->title);
        $this->assertArrayNotHasKey('integration_id', $original->fresh()->metadata);
    }

    #[Test]
    public function missing_account_keeps_history_and_remains_paused(): void
    {
        $original = $this->account();
        $this->stage();
        $this->provider($this->details('new-account', 'other-resource'));
        $this->assertTrue($this->plugin->completeRenewal($this->group, 'pending-reference', ['old-account' => '__missing']));
        $this->assertSame('account_missing', $this->integration->fresh()->configuration['gocardless_pause_reason']);
        $this->assertSame('old-account', $this->integration->fresh()->configuration['account_id']);
        $this->assertFalse($original->fresh()->trashed());
    }

    #[Test]
    public function expired_reference_and_other_owners_mapping_are_rejected(): void
    {
        $this->stage();
        $other = User::factory()->create();
        $this->actingAs($other)->get(route('integrations.gocardless.renewal.show', $this->group))->assertNotFound();
        $meta = $this->group->auth_metadata;
        $meta['gocardless_pending']['expires_at'] = now()->subMinute()->toISOString();
        $this->group->update(['auth_metadata' => $meta]);
        $this->expectException(RuntimeException::class);
        $this->plugin->completeRenewal($this->group, 'pending-reference');
    }

    #[Test]
    public function mapping_page_preserves_mobile_attempt_and_finishes_only_after_mapping(): void
    {
        $this->account();
        $this->stage();
        $meta = $this->group->auth_metadata;
        $meta['mobile_reauth_origin'] = true;
        $meta['mobile_reauth_attempt_id'] = 'mobile-attempt';
        $this->group->update(['auth_metadata' => $meta]);
        $this->provider($this->details('new-account', 'other-resource'));
        $this->plugin->completeRenewal($this->group, 'pending-reference');
        $this->app->instance(GoCardlessBankPlugin::class, $this->plugin);
        $this->actingAs($this->owner)->get(route('integrations.gocardless.renewal.show', $this->group))
            ->assertOk()->assertSee('Match your renewed accounts');
        $this->post(route('integrations.gocardless.renewal.store', $this->group), [
            'reference' => 'pending-reference', 'mapping' => ['old-account' => 'new-account'],
        ])->assertRedirect('spark://integrations/reauth-complete?status=success&attempt_id=mobile-attempt');
        $this->assertArrayNotHasKey('mobile_reauth_origin', $this->group->fresh()->auth_metadata);
    }

    #[Test]
    public function conflicting_repair_is_atomic_and_unknown_accounts_are_excluded_from_totals(): void
    {
        $first = $this->account();
        $second = $this->account('Conflicting settings');
        $meta = $second->metadata;
        $meta['is_negative_balance'] = false;
        $second->update(['metadata' => $meta]);
        $unknown = $this->account('Unidentified', ['account_id' => 'unknown']);
        $this->artisan('gocardless:reconcile-accounts', ['--user' => $this->owner->id, '--apply' => true])->assertFailed();
        $this->assertFalse($first->fresh()->trashed());
        $this->assertFalse($second->fresh()->trashed());
        $this->assertFalse((new FinancialPlugin)->getFinancialAccounts($this->owner)->contains($unknown));
    }

    #[Test]
    public function duplicate_reads_and_repair_preserve_both_histories_and_owner_boundaries(): void
    {
        $canonical = $this->account();
        $canonical->created_at = now()->subDay();
        $canonical->save();
        $duplicate = $this->account('Bank supplied name');
        $event = Event::create(['integration_id' => $this->integration->id, 'source_id' => 'balance-one',
            'actor_id' => $duplicate->id, 'target_id' => $canonical->id, 'service' => 'gocardless',
            'domain' => 'money', 'action' => 'had_balance', 'time' => now(), 'value' => 12345,
            'value_multiplier' => 100, 'value_unit' => 'GBP']);
        $relationship = Relationship::create(['user_id' => $this->owner->id, 'from_type' => EventObject::class,
            'from_id' => $duplicate->id, 'to_type' => EventObject::class, 'to_id' => $canonical->id, 'type' => 'related_to']);
        $otherOwner = User::factory()->create();
        $otherAccount = EventObject::create(['user_id' => $otherOwner->id, 'concept' => 'account', 'type' => 'bank_account',
            'title' => 'Other owner', 'metadata' => ['account_id' => 'old-account']]);
        $financial = new FinancialPlugin;
        $this->assertSame([$canonical->id], $financial->getFinancialAccounts($this->owner)->modelKeys());
        $this->assertSame($event->id, $financial->getLatestBalance($canonical)->id);
        $this->artisan('gocardless:reconcile-accounts', ['--user' => $this->owner->id])->assertSuccessful();
        $this->assertFalse($duplicate->fresh()->trashed());
        $this->artisan('gocardless:reconcile-accounts', ['--user' => $this->owner->id, '--apply' => true])->assertSuccessful();
        $this->assertSame($canonical->id, $event->fresh()->actor_id);
        $this->assertSame($canonical->id, $relationship->fresh()->from_id);
        $this->assertTrue($duplicate->fresh()->trashed());
        $this->assertFalse($otherAccount->fresh()->trashed());
        $this->assertSame($canonical->id, app(ObjectLookup::class)->find($this->owner, $duplicate->id)->id);
        $this->assertNull(app(ObjectLookup::class)->find($otherOwner, $duplicate->id));
        $this->artisan('gocardless:reconcile-accounts', ['--user' => $this->owner->id, '--apply' => true])->assertSuccessful();
        $this->assertSame(1, Event::where('integration_id', $this->integration->id)->count());
    }

    #[Test]
    public function renewed_aliases_keep_duplicate_history_visible_and_repairable(): void
    {
        $canonical = $this->account();
        $canonical->created_at = now()->subDay();
        $canonical->save();
        $duplicate = $this->account('Duplicate');
        $event = Event::create(['integration_id' => $this->integration->id, 'source_id' => 'old-alias-balance',
            'actor_id' => $duplicate->id, 'target_id' => $canonical->id, 'service' => 'gocardless', 'domain' => 'money',
            'action' => 'had_balance', 'time' => now(), 'value' => 5000,
            'value_multiplier' => 100, 'value_unit' => 'GBP']);
        $this->stage();
        $this->provider($this->details('new-account'));
        $this->assertTrue($this->plugin->completeRenewal($this->group, 'pending-reference'));
        $canonical->refresh();
        $financial = new FinancialPlugin;
        $this->assertSame([$canonical->id], $financial->getFinancialAccounts($this->owner)->modelKeys());
        $this->assertSame($event->id, $financial->getLatestBalance($canonical)->id);
        $this->assertSame($event->id, $financial->getLatestBalancesForAccounts(collect([$canonical]))->get($canonical->id)->id);
        $this->assertContains($event->id, $financial->getBalanceEventsQuery($canonical)->pluck('id')->all());
        $legacyIntegration = $this->plugin->createInstance($this->group, 'balances', ['account_id' => 'old-account']);
        $this->artisan('gocardless:reconcile-accounts', ['--user' => $this->owner->id])->assertSuccessful();
        $this->assertFalse($duplicate->fresh()->trashed());
        $this->artisan('gocardless:reconcile-accounts', ['--user' => $this->owner->id, '--apply' => true])->assertSuccessful();
        $this->assertTrue($duplicate->fresh()->trashed());
        $this->assertSame($canonical->id, $event->fresh()->actor_id);
        $this->assertSame($canonical->id, $legacyIntegration->fresh()->configuration['account_object_id']);
    }

    #[Test]
    public function old_completed_callback_cannot_complete_a_new_mobile_attempt(): void
    {
        $this->stage();
        $meta = $this->group->auth_metadata;
        $meta['gocardless_completed_reference'] = 'previous-reference';
        $meta['mobile_reauth_origin'] = true;
        $meta['mobile_reauth_attempt_id'] = 'current-mobile-attempt';
        $this->group->update(['auth_metadata' => $meta]);
        $this->app->instance(GoCardlessBankPlugin::class, $this->plugin);
        $this->actingAs($this->owner)->get(route('integrations.oauth.callback', [
            'service' => 'gocardless', 'ref' => 'previous-reference',
        ]))->assertRedirect(route('integrations.index'));
        $this->assertSame('current-mobile-attempt', $this->group->fresh()->auth_metadata['mobile_reauth_attempt_id']);
        $this->assertSame('old-requisition', $this->group->fresh()->account_id);
        $this->assertSame('generation-two', $this->group->fresh()->auth_metadata['gocardless_pending']['id']);
        $this->assertTrue($this->group->fresh()->auth_metadata['eua_expired']);
        Http::assertNothingSent();
    }

    #[Test]
    public function provider_cleanup_uses_the_exact_endpoint_and_retries_server_errors(): void
    {
        $url = 'https://bankaccountdata.gocardless.com/api/v2/requisitions/old-requisition/';
        Http::fake([$url => Http::sequence()->push([], 500)->push([], 204)->push([], 404)]);
        try {
            $this->plugin->deleteOldRequisition('old-requisition');
            $this->fail('A server failure must be retried.');
        } catch (RuntimeException) {
            // Retry the same operation, as the queued retirement job does.
        }
        $this->plugin->deleteOldRequisition('old-requisition');
        $this->plugin->deleteOldRequisition('old-requisition');
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request->url() === $url);
    }

    #[Test]
    public function sparse_bank_details_create_an_account_but_id_only_payloads_do_not(): void
    {
        $this->assertNull($this->plugin->upsertAccountObject($this->integration, ['id' => 'old-account']));
        $object = $this->plugin->upsertAccountObject($this->integration, [
            'id' => 'old-account', 'iban' => 'GB82WEST12345698765432', 'currency' => 'GBP',
        ]);
        $this->assertNotNull($object);
        $this->assertSame('old-account', $object->metadata['account_id']);
        $this->assertSame('GB82WEST12345698765432', $object->metadata['raw']['iban']);
        $this->assertSame($object->id, $this->plugin->upsertAccountObject($this->integration, ['id' => 'old-account'])->id);
        $this->assertSame($object->id, $this->integration->fresh()->configuration['account_object_id']);
    }

    private function details(string $id = 'old-account', string $resource = 'bank-resource'): array
    {
        return ['id' => $id, 'details' => 'Bank supplied name', 'resourceId' => $resource,
            'currency' => 'GBP', 'cashAccountType' => 'CARD'];
    }

    private function account(string $title = 'My card', ?array $metadata = null): EventObject
    {
        return EventObject::create(['user_id' => $this->owner->id, 'concept' => 'account',
            'type' => 'bank_account', 'title' => $title, 'metadata' => $metadata ?? [
                'account_id' => 'old-account', 'name' => $title, 'is_pinned' => true,
                'is_negative_balance' => true, 'raw' => $this->details(),
            ]]);
    }

    private function stage(): void
    {
        $meta = $this->group->auth_metadata;
        $meta['gocardless_pending'] = ['id' => 'generation-two', 'reference' => 'pending-reference',
            'requisition_id' => 'new-requisition', 'agreement_id' => 'new-agreement',
            'institution_id' => 'test-bank', 'link' => 'https://example.test/consent',
            'expires_at' => now()->addHour()->toISOString()];
        $this->group->update(['auth_metadata' => $meta]);
    }

    private function provider(array $details, string $status = 'LN'): void
    {
        Http::fake([
            '*/requisitions/new-requisition/' => Http::response(['status' => $status,
                'reference' => 'pending-reference', 'institution_id' => 'test-bank', 'accounts' => [$details['id']]]),
            '*/accounts/' . $details['id'] . '/details/' => Http::response(['account' => $details]),
        ]);
    }
}
