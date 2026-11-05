<?php

namespace Tests\Feature\Integrations;

use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PSEC-08 / INT-01 phase 2.
 *
 * ADR 0018 encrypted the credentials that live in their own columns. The ones
 * that live inside the jsonb columns were left because whole-column encryption
 * would break the SQL JSON paths the application relies on
 * (auth_metadata->gocardless_reference, configuration->migration_*).
 * EncryptedJsonSecrets encrypts only the secret leaves, so the structure — and
 * therefore every one of those paths — survives.
 */
class EncryptedJsonSecretsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    #[Test]
    public function an_api_key_round_trips_but_is_stored_as_ciphertext(): void
    {
        $group = $this->groupWith(['api_key' => 'immich_live_secret', 'server_url' => 'https://immich.example.com']);

        $this->assertSame('immich_live_secret', $group->fresh()->auth_metadata['api_key']);

        $raw = json_decode($this->rawAuthMetadata($group), true);

        $this->assertNotSame('immich_live_secret', $raw['api_key']);
        $this->assertSame('immich_live_secret', Crypt::decryptString($raw['api_key']));
    }

    #[Test]
    public function non_secret_leaves_stay_plaintext(): void
    {
        $group = $this->groupWith([
            'api_key' => 'immich_live_secret',
            'server_url' => 'https://immich.example.com',
            'gocardless_reference' => 'ref-abc-123',
        ]);

        $raw = json_decode($this->rawAuthMetadata($group), true);

        // server_url is in sensitive_log_keys() but is ordinary configuration
        // here — the encryption list is deliberately narrower than the log one.
        $this->assertSame('https://immich.example.com', $raw['server_url']);
        $this->assertSame('ref-abc-123', $raw['gocardless_reference']);
    }

    #[Test]
    public function the_gocardless_json_path_lookup_still_matches(): void
    {
        $group = $this->groupWith([
            'api_key' => 'immich_live_secret',
            'gocardless_reference' => 'ref-abc-123',
        ]);

        $found = IntegrationGroup::query()
            ->where('auth_metadata->gocardless_reference', 'ref-abc-123')
            ->first();

        $this->assertNotNull($found, 'The JSON path lookup must survive leaf encryption.');
        $this->assertSame((string) $group->id, (string) $found->id);
    }

    #[Test]
    public function fetch_session_cookies_are_encrypted_leaf_by_leaf(): void
    {
        // Cookie names are arbitrary, so name matching cannot reach these —
        // `cookies` is a secret subtree instead.
        $group = $this->groupWith([
            'domains' => [
                'example.com' => [
                    'cookies' => ['session_id' => 'abc123', 'csrf' => 'def456'],
                    'added_at' => '2026-01-01T00:00:00+00:00',
                ],
            ],
        ]);

        $raw = json_decode($this->rawAuthMetadata($group), true);
        $rawCookies = $raw['domains']['example.com']['cookies'];

        $this->assertSame('abc123', Crypt::decryptString($rawCookies['session_id']));
        $this->assertSame('def456', Crypt::decryptString($rawCookies['csrf']));
        $this->assertSame('2026-01-01T00:00:00+00:00', $raw['domains']['example.com']['added_at']);

        // assertEquals, not assertSame: postgres jsonb does not preserve key order.
        $this->assertEquals(
            ['session_id' => 'abc123', 'csrf' => 'def456'],
            $group->fresh()->auth_metadata['domains']['example.com']['cookies'],
        );
    }

    #[Test]
    public function a_row_written_before_the_cast_still_reads_correctly(): void
    {
        $group = $this->groupWith(['api_key' => 'placeholder']);

        // Simulate a row the backfill has not reached yet.
        DB::table('integration_groups')->where('id', $group->id)->update([
            'auth_metadata' => json_encode(['api_key' => 'legacy_plaintext', 'days_back' => 7]),
        ]);

        $this->assertSame('legacy_plaintext', $group->fresh()->auth_metadata['api_key']);
        $this->assertSame(7, $group->fresh()->auth_metadata['days_back']);
    }

    #[Test]
    public function an_integration_configuration_api_key_is_encrypted(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'configuration' => ['api_key' => 'hev_live_secret', 'days_back' => 30],
        ]);

        $this->assertSame('hev_live_secret', $integration->fresh()->configuration['api_key']);

        $raw = json_decode(
            (string) DB::table('integrations')->where('id', $integration->id)->value('configuration'),
            true,
        );

        $this->assertSame('hev_live_secret', Crypt::decryptString($raw['api_key']));
        $this->assertSame(30, $raw['days_back']);
    }

    #[Test]
    public function a_migration_status_json_path_update_leaves_the_secret_intact(): void
    {
        $integration = Integration::factory()->create([
            'user_id' => $this->user->id,
            'configuration' => ['api_key' => 'hev_live_secret', 'days_back' => 30],
        ]);

        // This is how StartIntegrationMigration writes: a SQL JSON path update
        // that never round-trips through the cast.
        Integration::query()->whereKey($integration->id)->update([
            'configuration->migration_status' => 'started',
        ]);

        $fresh = $integration->fresh();

        $this->assertSame('started', $fresh->configuration['migration_status']);
        $this->assertSame('hev_live_secret', $fresh->configuration['api_key']);
        $this->assertSame(30, $fresh->configuration['days_back']);
    }

    #[Test]
    public function re_saving_does_not_double_encrypt(): void
    {
        $group = $this->groupWith(['api_key' => 'immich_live_secret']);

        $group->update(['auth_metadata' => $group->fresh()->auth_metadata]);

        $this->assertSame('immich_live_secret', $group->fresh()->auth_metadata['api_key']);
    }

    #[Test]
    public function the_backfill_encrypts_a_legacy_plaintext_row(): void
    {
        $group = $this->groupWith(['api_key' => 'placeholder']);

        DB::table('integration_groups')->where('id', $group->id)->update([
            'auth_metadata' => json_encode(['api_key' => 'legacy_plaintext', 'days_back' => 7]),
        ]);

        $this->artisan('integrations:encrypt-credentials')->assertSuccessful();

        $raw = json_decode($this->rawAuthMetadata($group), true);

        $this->assertSame('legacy_plaintext', Crypt::decryptString($raw['api_key']));
        $this->assertSame(7, $raw['days_back']);
        $this->assertSame('legacy_plaintext', $group->fresh()->auth_metadata['api_key']);
    }

    #[Test]
    public function the_backfill_is_idempotent(): void
    {
        $group = $this->groupWith(['api_key' => 'immich_live_secret']);

        $this->artisan('integrations:encrypt-credentials')->assertSuccessful();
        $afterFirst = $this->rawAuthMetadata($group);

        $this->artisan('integrations:encrypt-credentials')->assertSuccessful();

        $this->assertSame($afterFirst, $this->rawAuthMetadata($group));
        $this->assertSame('immich_live_secret', $group->fresh()->auth_metadata['api_key']);
    }

    /**
     * @param  array<string, mixed>  $authMetadata
     */
    private function groupWith(array $authMetadata): IntegrationGroup
    {
        return IntegrationGroup::create([
            'user_id' => $this->user->id,
            'service' => 'immich',
            'account_id' => 'acc_123',
            'auth_metadata' => $authMetadata,
        ]);
    }

    private function rawAuthMetadata(IntegrationGroup $group): string
    {
        return (string) DB::table('integration_groups')->where('id', $group->id)->value('auth_metadata');
    }
}
