<?php

namespace App\Console\Commands;

use App\Models\ActionProgress;
use App\Services\Notifications\NotificationIncidentResolver;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

class MaintainNotificationHistory extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:maintain-history
        {--dry-run : Report changes without writing them}
        {--stale-hours=24 : Fail in-progress activity with no updates for this many hours}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Archive expired notification feed items, fail abandoned activity and prune terminal history after 30 days';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $archived = 0;
        $deletedNotifications = 0;
        $deletedActivities = 0;
        $staleHours = max(1, (int) $this->option('stale-hours'));

        $resolver = app(NotificationIncidentResolver::class);
        DatabaseNotification::query()->whereNull('archived_at')->chunkById(250, function ($notifications) use ($resolver, $dryRun, &$archived) {
            $archived += $resolver->reconcileBatch($notifications, $dryRun);
        });

        $staleActivities = ActionProgress::query()
            ->whereNull('completed_at')
            ->whereNull('failed_at')
            ->where('updated_at', '<', now()->subHours($staleHours));

        if ($dryRun) {
            $failedActivities = $staleActivities->count();
        } else {
            $failedActivities = 0;
            $staleActivities->chunkById(250, function ($activities) use ($staleHours, &$failedActivities) {
                foreach ($activities as $activity) {
                    $activity->markFailed("Stopped responding: no progress for over {$staleHours} hours", [
                        'abandoned_step' => $activity->step,
                        'abandoned_message' => $activity->message,
                    ]);
                    $failedActivities++;
                }
            });
        }

        $notificationHistory = DatabaseNotification::query()
            ->whereNotNull('archived_at')
            ->where('archived_at', '<', now()->subDays(30));
        $terminalActivities = ActionProgress::query()
            ->where(function ($query) {
                $query->whereNotNull('completed_at')->orWhereNotNull('failed_at');
            })
            ->where('updated_at', '<', now()->subDays(30));

        if ($dryRun) {
            $deletedNotifications = $notificationHistory->count();
            $deletedActivities = $terminalActivities->count();
        } else {
            $deletedNotifications = $notificationHistory->delete();
            $deletedActivities = $terminalActivities->delete();
        }

        $this->table(
            ['Mode', 'Archived', 'Failed stale activities', 'Deleted notifications', 'Deleted activities'],
            [[
                $dryRun ? 'dry-run' : 'write',
                $archived,
                $failedActivities,
                $deletedNotifications,
                $deletedActivities,
            ]],
        );

        return self::SUCCESS;
    }
}
