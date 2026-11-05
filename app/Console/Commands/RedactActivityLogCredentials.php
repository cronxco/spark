<?php

namespace App\Console\Commands;

use App\Casts\EncryptedJsonSecrets;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Redacts credentials already written into the activity log.
 *
 * Before RedactsLoggedProperties, Spatie's logFillable() recorded whatever the
 * casts returned — so every changelog entry for an Integration or an
 * IntegrationGroup could carry an api_key, an access token or a session cookie
 * in plaintext, in a table that is neither encrypted nor rotated.
 *
 * The values are rewritten in place rather than the rows deleted: the audit
 * trail is the point of the table, and every non-secret field in the diff stays
 * exactly as it was.
 *
 * Deliberately a command rather than a migration: re-runnable, interruptible,
 * and safe to stop halfway. Rows that are already clean are left untouched, so
 * it can be run repeatedly.
 */
class RedactActivityLogCredentials extends Command
{
    protected $signature = 'activity-log:redact-credentials
                            {--batch-size=500 : Number of log rows to process per batch}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Redact credentials recorded in activity log properties for integrations';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $batchSize = max(1, (int) $this->option('batch-size'));
        $table = config('activitylog.table_name', 'activity_log');

        $subjectTypes = [Integration::class, IntegrationGroup::class];

        $total = DB::table($table)->whereIn('subject_type', $subjectTypes)->count();

        if ($total === 0) {
            $this->info('No integration activity log rows to process.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[dry run] ' : '') . "Scanning {$total} activity log row(s).");

        $redacted = 0;
        $alreadyClean = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        DB::table($table)
            ->select(['id', 'properties'])
            ->whereIn('subject_type', $subjectTypes)
            ->orderBy('id')
            ->chunk($batchSize, function ($rows) use ($table, $dryRun, &$redacted, &$alreadyClean, &$failed, $bar) {
                foreach ($rows as $row) {
                    $bar->advance();

                    $properties = json_decode((string) $row->properties, true);

                    if (! is_array($properties)) {
                        $alreadyClean++;

                        continue;
                    }

                    $sanitised = EncryptedJsonSecrets::redact($properties);

                    if ($sanitised === $properties) {
                        $alreadyClean++;

                        continue;
                    }

                    if ($dryRun) {
                        $redacted++;

                        continue;
                    }

                    try {
                        DB::table($table)
                            ->where('id', $row->id)
                            ->update(['properties' => json_encode($sanitised)]);
                        $redacted++;
                    } catch (Throwable $e) {
                        $failed++;
                        $this->newLine();
                        $this->error("Failed to redact activity log row {$row->id}: {$e->getMessage()}");
                    }
                }
            });

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Outcome', 'Count'],
            [
                [$dryRun ? 'Would redact' : 'Redacted', $redacted],
                ['Already clean', $alreadyClean],
                ['Failed', $failed],
            ],
        );

        if ($failed > 0) {
            $this->warn('Some rows failed. The command is idempotent — fix the cause and run it again.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
