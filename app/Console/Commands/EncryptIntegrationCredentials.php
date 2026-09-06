<?php

namespace App\Console\Commands;

use App\Casts\EncryptedJsonSecrets;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Converts plaintext connector credentials to application-encrypted values.
 *
 * Covers both shapes: the dedicated `text` columns on IntegrationGroup, and the
 * secret leaves inside the `jsonb` columns — IntegrationGroup::$auth_metadata
 * and Integration::$configuration — that EncryptedJsonSecrets governs.
 *
 * Deliberately a command rather than a migration: it is re-runnable,
 * interruptible, and safe to stop halfway, none of which a migration offers.
 * Rows that already hold ciphertext are skipped, so it can be run repeatedly
 * and can resume after a failure without corrupting anything.
 *
 * Reads and writes go through the query builder rather than the model so the
 * casts do not interfere: we need the raw stored value to decide whether it has
 * already been converted.
 */
class EncryptIntegrationCredentials extends Command
{
    /** @var array<int, string> */
    private const ENCRYPTED_COLUMNS = ['access_token', 'refresh_token', 'webhook_secret'];

    /**
     * The jsonb columns EncryptedJsonSecrets is applied to.
     *
     * @var array<int, array{table: string, column: string}>
     */
    private const JSON_COLUMNS = [
        ['table' => 'integration_groups', 'column' => 'auth_metadata'],
        ['table' => 'integrations', 'column' => 'configuration'],
    ];

    protected $signature = 'integrations:encrypt-credentials
                            {--batch-size=200 : Number of credential groups to process per batch}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Encrypt plaintext credentials on integration groups and integrations';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $batchSize = max(1, (int) $this->option('batch-size'));

        $columnResult = $this->encryptCredentialColumns($dryRun, $batchSize);
        $jsonResult = $this->encryptJsonSecrets($dryRun, $batchSize);

        return $columnResult === self::SUCCESS && $jsonResult === self::SUCCESS
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * Pass one: the dedicated `text` credential columns on IntegrationGroup.
     */
    private function encryptCredentialColumns(bool $dryRun, int $batchSize): int
    {
        $total = DB::table('integration_groups')->count();

        if ($total === 0) {
            $this->info('No integration groups to process.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[dry run] ' : '') . "Scanning {$total} integration group(s).");

        $converted = 0;
        $alreadyEncrypted = 0;
        $empty = 0;
        $concurrentlyChanged = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        DB::table('integration_groups')
            ->select(array_merge(['id'], self::ENCRYPTED_COLUMNS))
            ->orderBy('id')
            ->chunk($batchSize, function ($groups) use ($dryRun, &$converted, &$alreadyEncrypted, &$empty, &$concurrentlyChanged, &$failed, $bar) {
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
                        $query = DB::table('integration_groups')->where('id', $group->id);

                        foreach (array_keys($updates) as $column) {
                            $query->where($column, $group->{$column});
                        }

                        if ($query->update($updates) === 0) {
                            $concurrentlyChanged++;
                            $bar->advance();

                            continue;
                        }

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
                ['Concurrently changed (skipped)', $concurrentlyChanged],
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
     * Pass two: the secret leaves inside the jsonb columns.
     *
     * Mirrors EncryptedJsonSecrets exactly — same key lists, same recursion —
     * but reads and writes raw JSON so the cast does not decrypt on the way in
     * and re-encrypt on the way out, which would make the "already converted"
     * test impossible.
     */
    private function encryptJsonSecrets(bool $dryRun, int $batchSize): int
    {
        $converted = 0;
        $alreadyEncrypted = 0;
        $empty = 0;
        $concurrentlyChanged = 0;
        $failed = 0;

        foreach (self::JSON_COLUMNS as ['table' => $table, 'column' => $column]) {
            $total = DB::table($table)->count();

            if ($total === 0) {
                continue;
            }

            $this->newLine();
            $this->info(($dryRun ? '[dry run] ' : '') . "Scanning {$total} row(s) for secrets in {$table}.{$column}.");

            $bar = $this->output->createProgressBar($total);
            $bar->start();

            DB::table($table)
                ->select(['id', $column])
                ->orderBy('id')
                ->chunk($batchSize, function ($rows) use ($table, $column, $dryRun, &$converted, &$alreadyEncrypted, &$empty, &$concurrentlyChanged, &$failed, $bar) {
                    foreach ($rows as $row) {
                        $bar->advance();

                        $decoded = json_decode((string) $row->{$column}, true);

                        if (! is_array($decoded) || $decoded === []) {
                            $empty++;

                            continue;
                        }

                        $encrypted = $this->encryptSecretLeaves($decoded, false);

                        if ($encrypted === $decoded) {
                            $alreadyEncrypted++;

                            continue;
                        }

                        if ($dryRun) {
                            $converted++;

                            continue;
                        }

                        try {
                            $updated = DB::table($table)
                                ->where('id', $row->id)
                                ->where($column, $row->{$column})
                                ->update([$column => json_encode($encrypted)]);

                            if ($updated === 0) {
                                $concurrentlyChanged++;

                                continue;
                            }

                            $converted++;
                        } catch (Throwable $e) {
                            $failed++;
                            $this->newLine();
                            $this->error("Failed to encrypt {$table}.{$column} for {$row->id}: {$e->getMessage()}");
                        }
                    }
                });

            $bar->finish();
            $this->newLine(2);
        }

        $this->table(
            ['Outcome', 'Count'],
            [
                [$dryRun ? 'Would encrypt (json rows)' : 'Encrypted (json rows)', $converted],
                ['Already encrypted', $alreadyEncrypted],
                ['No secrets (skipped)', $empty],
                ['Concurrently changed (skipped)', $concurrentlyChanged],
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
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function encryptSecretLeaves(array $data, bool $inheritedSecret): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            $isSecretSubtree = $inheritedSecret || in_array($lowerKey, EncryptedJsonSecrets::SECRET_SUBTREES, true);
            $isSecretLeaf = $isSecretSubtree || in_array($lowerKey, EncryptedJsonSecrets::SECRET_KEYS, true);

            if (is_array($value)) {
                $result[$key] = $this->encryptSecretLeaves($value, $isSecretSubtree);

                continue;
            }

            $result[$key] = $isSecretLeaf && is_string($value) && $value !== '' && ! $this->isEncrypted($value)
                ? Crypt::encryptString($value)
                : $value;
        }

        return $result;
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
