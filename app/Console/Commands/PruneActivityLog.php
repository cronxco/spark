<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Applies the activity log retention policy (decision D-API-6): change rows
 * are kept for 90 days, security-relevant rows for a year.
 *
 * Security-relevant means the `security` log (operator access, for example)
 * and changes to users, integrations and integration groups, which is where
 * credentials, configuration and account changes are recorded.
 *
 * Counts only by default, so the first run shows what would go. Nothing is
 * deleted without --execute. Deletes in id batches so the table is never
 * locked for long.
 */
class PruneActivityLog extends Command
{
    public const CHANGE_RETENTION_DAYS = 90;

    public const SECURITY_RETENTION_DAYS = 365;

    public const SECURITY_LOG_NAME = 'security';

    /** @var array<int, class-string> */
    public const SECURITY_SUBJECTS = [User::class, Integration::class, IntegrationGroup::class];

    protected $signature = 'activity-log:prune
                            {--execute : Delete the rows instead of only counting them}
                            {--batch-size=5000 : Rows deleted per batch}';

    protected $description = 'Count, or with --execute delete, activity log rows past the retention policy';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $batchSize = max(1, (int) $this->option('batch-size'));

        $changeCutoff = now()->subDays(self::CHANGE_RETENTION_DAYS);
        $securityCutoff = now()->subDays(self::SECURITY_RETENTION_DAYS);

        $changeRows = $this->changeRows($changeCutoff->toDateTimeString())->count();
        $securityRows = $this->securityRows($securityCutoff->toDateTimeString())->count();

        $this->table(['Rows', 'Older than', 'Count'], [
            ['Change rows', $changeCutoff->toDateString(), number_format($changeRows)],
            ['Security rows', $securityCutoff->toDateString(), number_format($securityRows)],
            ['Total', '', number_format($changeRows + $securityRows)],
        ]);

        if (! $execute) {
            $this->info('Dry run: nothing was deleted. Run again with --execute to delete these rows.');

            return self::SUCCESS;
        }

        $deleted = $this->deleteInBatches(fn (): Builder => $this->changeRows($changeCutoff->toDateTimeString()), $batchSize)
            + $this->deleteInBatches(fn (): Builder => $this->securityRows($securityCutoff->toDateTimeString()), $batchSize);

        $this->info('Deleted ' . number_format($deleted) . ' activity log row(s).');

        return self::SUCCESS;
    }

    private function changeRows(string $cutoff): Builder
    {
        return $this->activityLog()
            ->where('created_at', '<', $cutoff)
            ->where(fn (Builder $query) => $query->whereNull('log_name')->orWhere('log_name', '!=', self::SECURITY_LOG_NAME))
            ->where(fn (Builder $query) => $query->whereNull('subject_type')->orWhereNotIn('subject_type', self::SECURITY_SUBJECTS));
    }

    private function securityRows(string $cutoff): Builder
    {
        return $this->activityLog()
            ->where('created_at', '<', $cutoff)
            ->where(fn (Builder $query) => $query->where('log_name', self::SECURITY_LOG_NAME)->orWhereIn('subject_type', self::SECURITY_SUBJECTS));
    }

    /**
     * @param  callable(): Builder  $rows
     */
    private function deleteInBatches(callable $rows, int $batchSize): int
    {
        $deleted = 0;

        do {
            $ids = $rows()->orderBy('id')->limit($batchSize)->pluck('id');
            $count = $ids->isEmpty() ? 0 : $this->activityLog()->whereIn('id', $ids)->delete();
            $deleted += $count;
        } while ($count > 0);

        return $deleted;
    }

    private function activityLog(): Builder
    {
        return DB::table(config('activitylog.table_name', 'activity_log'));
    }
}
