<?php

namespace Tests\Feature\Integrations;

use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * PSEC-08 / INT-01 phase 1.
 *
 * Access tokens, refresh tokens and webhook secrets are encrypted at the
 * application boundary (ADR 0018). `auth_metadata` is deliberately excluded —
 * it is a jsonb column read through SQL JSON paths, so whole-column encryption
 * would break both the column type and those queries.
 */
class IntegrationGroupCredentialEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private function makeGroup(array $attributes = []): IntegrationGroup
    {
        return IntegrationGroup::factory()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'access_token' => 'plain-access-token',
            'refresh_token' => 'plain-refresh-token',
            'webhook_secret' => 'plain-webhook-secret',
        ], $attributes));
    }

    #[Test]
    public function credentials_round_trip_through_the_encrypted_cast(): void
    {
        $group = $this->makeGroup();

        $this->assertSame('plain-access-token', $group->fresh()->access_token);
        $this->assertSame('plain-refresh-token', $group->fresh()->refresh_token);
        $this->assertSame('plain-webhook-secret', $group->fresh()->webhook_secret);
    }

    #[Test]
    public function credentials_are_not_stored_in_plaintext(): void
    {
        $group = $this->makeGroup();

        $raw = DB::table('integration_groups')->where('id', $group->id)->first();

        foreach (['access_token', 'refresh_token', 'webhook_secret'] as $column) {
            $this->assertNotSame("plain-{$this->columnLabel($column)}", $raw->{$column});
            $this->assertSame(
                "plain-{$this->columnLabel($column)}",
                Crypt::decryptString($raw->{$column}),
                "Column {$column} should hold decryptable ciphertext.",
            );
        }
    }

    #[Test]
    public function null_credentials_stay_null(): void
    {
        $group = $this->makeGroup([
            'access_token' => null,
            'refresh_token' => null,
            'webhook_secret' => null,
        ]);

        $this->assertNull($group->fresh()->access_token);

        $raw = DB::table('integration_groups')->where('id', $group->id)->first();
        $this->assertNull($raw->access_token);
    }

    #[Test]
    public function auth_metadata_remains_queryable_json(): void
    {
        $group = $this->makeGroup(['auth_metadata' => ['gocardless_reference' => 'ref-123']]);

        // The GoCardless callback resolves the group by a JSON path, which only
        // works while the column holds real jsonb.
        $found = IntegrationGroup::query()
            ->where('auth_metadata->gocardless_reference', 'ref-123')
            ->first();

        $this->assertNotNull($found);
        $this->assertSame($group->id, $found->id);
    }

    #[Test]
    public function the_backfill_command_encrypts_existing_plaintext_rows(): void
    {
        $group = $this->makeGroup();

        // Simulate a pre-migration row by writing plaintext past the cast.
        DB::table('integration_groups')->where('id', $group->id)->update([
            'access_token' => 'legacy-plaintext',
            'refresh_token' => null,
            'webhook_secret' => 'legacy-secret',
        ]);

        $this->artisan('integrations:encrypt-credentials')->assertSuccessful();

        $raw = DB::table('integration_groups')->where('id', $group->id)->first();
        $this->assertSame('legacy-plaintext', Crypt::decryptString($raw->access_token));
        $this->assertSame('legacy-secret', Crypt::decryptString($raw->webhook_secret));
        $this->assertNull($raw->refresh_token);
        $this->assertSame('legacy-plaintext', $group->fresh()->access_token);
    }

    #[Test]
    public function the_backfill_command_is_idempotent(): void
    {
        $group = $this->makeGroup();

        DB::table('integration_groups')->where('id', $group->id)->update([
            'access_token' => 'legacy-plaintext',
        ]);

        $this->artisan('integrations:encrypt-credentials')->assertSuccessful();
        $afterFirst = DB::table('integration_groups')->where('id', $group->id)->value('access_token');

        // A second run must not double-encrypt an already-converted value.
        $this->artisan('integrations:encrypt-credentials')->assertSuccessful();
        $afterSecond = DB::table('integration_groups')->where('id', $group->id)->value('access_token');

        $this->assertSame('legacy-plaintext', Crypt::decryptString($afterSecond));
        $this->assertSame(
            Crypt::decryptString($afterFirst),
            Crypt::decryptString($afterSecond),
        );
    }

    #[Test]
    public function the_activity_log_does_not_record_credentials(): void
    {
        $group = $this->makeGroup();

        // Change a non-credential attribute alongside a credential;
        // dontLogIfAttributesChangedOnly() would not suppress this log, so only
        // logExcept() keeps the secrets out of the diff.
        $group->update([
            'account_id' => 'account-changed',
            'access_token' => 'rotated-access-token',
        ]);

        $activities = Activity::query()->get();
        $serialised = $activities->pluck('properties')->toJson();

        $this->assertStringNotContainsString('rotated-access-token', $serialised);
        $this->assertStringNotContainsString('plain-access-token', $serialised);
        $this->assertStringNotContainsString('plain-webhook-secret', $serialised);
    }

    private function columnLabel(string $column): string
    {
        return match ($column) {
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'webhook_secret' => 'webhook-secret',
        };
    }
}
