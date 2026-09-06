<?php

namespace App\Console\Commands;

use App\Support\SparkAbility;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Revokes personal access tokens that still carry the `*` wildcard ability.
 *
 * SparkAbility::canDelegate() now refuses to mint one, but tokens issued before
 * that check still satisfy every tokenCan() in the application — including the
 * non-delegable `ios:*` and `mcp:read` abilities. Nothing else in the codebase
 * revokes them, so they have to be swept explicitly.
 *
 * Deliberately a command rather than a migration: it is re-runnable,
 * interruptible, and safe to stop halfway, none of which a migration offers.
 * The dry run prints the full inventory so the blast radius can be read before
 * anything is destroyed.
 *
 * Reads go through the query builder rather than the model so the stored
 * `abilities` JSON is inspected exactly as written.
 */
class RevokeWildcardTokens extends Command
{
    protected $signature = 'tokens:revoke-wildcard
                            {--batch-size=200 : Number of tokens to process per batch}
                            {--dry-run : Report what would be revoked without writing}';

    protected $description = 'Revoke personal access tokens holding the * wildcard ability';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $batchSize = max(1, (int) $this->option('batch-size'));

        $wildcards = [];

        DB::table('personal_access_tokens')
            ->select(['id', 'name', 'tokenable_type', 'tokenable_id', 'abilities', 'created_at', 'last_used_at'])
            ->orderBy('id')
            ->chunk($batchSize, function ($tokens) use (&$wildcards) {
                foreach ($tokens as $token) {
                    if ($this->isWildcard($token->abilities)) {
                        $wildcards[] = $token;
                    }
                }
            });

        if ($wildcards === []) {
            $this->info('No wildcard tokens found.');

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            '%s%d token(s) hold the "*" ability and can therefore do anything the owning user can.',
            $dryRun ? '[dry run] ' : '',
            count($wildcards),
        ));

        $this->newLine();
        $this->table(
            ['ID', 'Name', 'Owner', 'Created', 'Last used'],
            array_map(fn ($token) => [
                $token->id,
                $token->name,
                $token->tokenable_id,
                $token->created_at,
                $token->last_used_at ?? 'never',
            ], $wildcards),
        );

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry run: nothing was revoked. Re-run without --dry-run to revoke these tokens.');

            return self::SUCCESS;
        }

        $revoked = 0;
        $refreshRevoked = 0;
        $failed = 0;

        foreach ($wildcards as $token) {
            try {
                $refreshRevoked += DB::transaction(function () use ($token): int {
                    // Deleting the access token alone would leave its paired
                    // refresh token able to mint a replacement, so both go —
                    // the same pairing OAuthController::logout() acts on.
                    $revokedRefreshTokens = DB::table('oauth_refresh_tokens')
                        ->where('access_token_id', $token->id)
                        ->whereNull('revoked_at')
                        ->update(['revoked_at' => now()]);

                    DB::table('personal_access_tokens')->where('id', $token->id)->delete();

                    return $revokedRefreshTokens;
                });

                $revoked++;
            } catch (Throwable $e) {
                $failed++;
                $this->error("Failed to revoke token {$token->id}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->table(
            ['Outcome', 'Count'],
            [
                ['Access tokens revoked', $revoked],
                ['Paired refresh tokens revoked', $refreshRevoked],
                ['Failed', $failed],
            ],
        );

        if ($failed > 0) {
            $this->warn('Some tokens failed. The command is idempotent — fix the cause and run it again.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Clients must now re-authenticate and request explicit abilities from: %s',
            implode(', ', SparkAbility::DELEGABLE),
        ));

        return self::SUCCESS;
    }

    /**
     * Whether a stored abilities column grants the wildcard.
     */
    private function isWildcard(?string $abilities): bool
    {
        if ($abilities === null || $abilities === '') {
            return false;
        }

        $decoded = json_decode($abilities, true);

        return is_array($decoded) && in_array('*', $decoded, true);
    }
}
