<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Converts plaintext connector credentials to application-encrypted values.
 *
 * Deliberately a command rather than a migration: it is re-runnable,
 * interruptible, and safe to stop halfway, none of which a migration offers.
 * Rows that already hold ciphertext are skipped, so it can be run repeatedly
 * and can resume after a failure without corrupting anything.
 *
 * Reads and writes go through the query builder rather than the model so the
 * `encrypted` casts on IntegrationGroup do not interfere: we need the raw
 * stored value to decide whether it has already been converted.
 */
class EncryptIntegrationCredentials extends Command
{
    /** @var array<int, string> */
    private const ENCRYPTED_COLUMNS = ['access_token', 'refresh_token', 'webhook_secret'];
    protected $signature = 'integrations:encrypt-credentials
                            {--batch-size=200 : Number of credential groups to process per batch}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Encrypt plaintext access tokens, refresh tokens and webhook secrets on integration groups';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $batchSize = max(1, (int) $this->option('batch-size'));

        $total = DB::table('integration_groups')->count();

        if ($total === 0) {
            $this->info('No integration groups to process.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[dry run] ' : '') . "Scanning {$total} integration group(s).");

        $converted = 0;
        $alreadyEncrypted = 0;
        $empty = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        DB::table('integration_groups')
            ->select(array_merge(['id'], self::ENCRYPTED_COLUMNS))
            ->orderBy('id')
            ->chunk($batchSize, function ($groups) use ($dryRun, &$converted, &$alreadyEncrypted, &$empty, &$failed, $bar) {
                foreach ($groups as $group) {
                    $updates = [];

                    foreach (self::ENCRYPTED_COLUMNS as $column) {
                        $value = $group->{$column};

                        if ($value === null || $value === '') {
                            $empty++;

                            continue;
                        }

                        if ($this->isEncrypted($value)) {
                            $alreadyEncrypted++;

                            continue;
                        }

                        $updates[$column] = Crypt::encryptString($value);
                    }

                    if ($updates === []) {
                        $bar->advance();

                        continue;
                    }

                    if ($dryRun) {
                        $converted += count($updates);
                        $bar->advance();

                        continue;
                    }

                    try {
                        DB::table('integration_groups')->where('id', $group->id)->update($updates);
                        $converted += count($updates);
                    } catch (Throwable $e) {
                        $failed++;
                        $this->newLine();
                        $this->error("Failed to encrypt credentials for group {$group->id}: {$e->getMessage()}");
                    }

                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Outcome', 'Count'],
            [
                [$dryRun ? 'Would encrypt' : 'Encrypted', $converted],
                ['Already encrypted', $alreadyEncrypted],
                ['Empty (skipped)', $empty],
                ['Failed', $failed],
            ],
        );

        if ($failed > 0) {
            $this->warn('Some rows failed. The command is idempotent — fix the cause and run it again.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Whether a stored value is already Laravel ciphertext.
     *
     * Attempting the decrypt is the only reliable test: a plaintext provider
     * token could in principle be base64-shaped, but it will not carry a valid
     * MAC for this APP_KEY.
     */
    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
