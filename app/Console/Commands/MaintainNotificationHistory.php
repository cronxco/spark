<?php

namespace App\Console\Commands;

use App\Models\ActionProgress;
use App\Notifications\NotificationCatalogue;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

class MaintainNotificationHistory extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:maintain-history
        {--dry-run : Report changes without writing them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Archive expired notification feed items and prune terminal history after 30 days';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $archived = 0;
        $deletedNotifications = 0;
        $deletedActivities = 0;

        foreach (NotificationCatalogue::all() as $type => $definition) {
            $activeHours = $definition['active_hours'];
            if ($activeHours === null) {
                continue;
            }

            $query = DatabaseNotification::query()
                ->where('type', $type)
                ->whereNull('archived_at')
                ->where('updated_at', '<=', now()->subHours($activeHours));

            if ($dryRun) {
                $archived += $query->count();

                continue;
            }

            $query->chunkById(250, function ($notifications) use (&$archived) {
                DB::transaction(function () use ($notifications, &$archived) {
                    foreach ($notifications as $notification) {
                        $data = is_array($notification->data) ? $notification->data : [];
                        $notification->forceFill([
                            'archived_at' => now(),
                            'data' => [...$data, 'archive_reason' => 'expired'],
                        ])->save();
                        $archived++;
                    }
                });
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
            ['Mode', 'Archived', 'Deleted notifications', 'Deleted activities'],
            [[
                $dryRun ? 'dry-run' : 'write',
                $archived,
                $deletedNotifications,
                $deletedActivities,
            ]],
        );

        return self::SUCCESS;
    }
}
