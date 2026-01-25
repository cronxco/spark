<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\User;
use App\Services\TransactionLinking\TransactionLinkingService;
use Illuminate\Console\Command;

class LinkTransactions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transactions:link
                            {--user= : User ID to process (leave empty for all users)}
                            {--threshold=85 : Confidence threshold for auto-approval (0-100)}
                            {--batch=100 : Number of records to process in each batch}
                            {--limit= : Maximum number of events to process}
                            {--dry-run : Show what would be linked without creating relationships}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find and link related transactions across accounts';

    /**
     * Execute the console command.
     */
    public function handle(TransactionLinkingService $linkingService): int
    {
        $userId = $this->option('user');
        $threshold = (float) $this->option('threshold');
        $batchSize = (int) $this->option('batch');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $dryRun = $this->option('dry-run');

        $this->info('Starting transaction linking...');
        $this->info('Threshold: ' . $threshold . '%');
        $this->info('Batch size: ' . $batchSize);

        if ($dryRun) {
            $this->warn('DRY RUN - No relationships will be created');
        }

        // Get users to process
        $users = $userId
            ? User::where('id', $userId)->get()
            : User::all();

        if ($users->isEmpty()) {
            $this->error('No users found to process');

            return Command::FAILURE;
        }

        $this->info('Processing ' . $users->count() . ' user(s)');

        $totalStats = ['created' => 0, 'pending' => 0, 'skipped' => 0, 'processed' => 0];

        foreach ($users as $user) {
            $this->info("\nProcessing user: {$user->email}");

            if ($dryRun) {
                $stats = $this->dryRunForUser($user, $linkingService, $batchSize, $limit);
            } else {
                $stats = $linkingService->processAllEventsForUser(
                    $user->id,
                    $limit,
                    $threshold
                );
            }

            $this->table(
                ['Metric', 'Count'],
                [
                    ['Events Processed', $stats['processed']],
                    ['Links Created', $stats['created']],
                    ['Pending Review', $stats['pending']],
                    ['Skipped', $stats['skipped']],
                ]
            );

            $totalStats['created'] += $stats['created'];
            $totalStats['pending'] += $stats['pending'];
            $totalStats['skipped'] += $stats['skipped'];
            $totalStats['processed'] += $stats['processed'];
        }

        $this->newLine();
        $this->info('=== SUMMARY ===');
        $this->table(
            ['Metric', 'Total'],
            [
                ['Events Processed', $totalStats['processed']],
                ['Links Created', $totalStats['created']],
                ['Pending Review', $totalStats['pending']],
                ['Skipped', $totalStats['skipped']],
            ]
        );

        if ($totalStats['pending'] > 0) {
            $this->info("\nVisit /admin/pending-links to review pending matches");
        }

        return Command::SUCCESS;
    }

    /**
     * Perform a dry run for a user.
     */
    private function dryRunForUser(
        User $user,
        TransactionLinkingService $linkingService,
        int $batchSize,
        ?int $limit
    ): array {
        $stats = ['created' => 0, 'pending' => 0, 'skipped' => 0, 'processed' => 0];

        $query = Event::whereHas('integration', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })
            ->where('domain', 'money')
            ->orderBy('time', 'desc');

        if ($limit) {
            $query->limit($limit);
        }

        $bar = $this->output->createProgressBar($query->count());
        $bar->start();

        $query->chunk($batchSize, function ($events) use (&$stats, $linkingService, $bar) {
            foreach ($events as $event) {
                $event->loadMissing('integration');

                foreach ($linkingService->getStrategies() as $strategy) {
                    if (! $strategy->canProcess($event)) {
                        continue;
                    }

                    $links = $strategy->findLinks($event);

                    foreach ($links as $link) {
                        if ($link['confidence'] >= 85) {
                            $this->newLine();
                            $this->line(sprintf(
                                '  [WOULD CREATE] %s -> %s (%s, %.1f%%)',
                                $event->source_id,
                                $link['target_event']->source_id,
                                $link['relationship_type'],
                                $link['confidence']
                            ));
                            $stats['created']++;
                        } else {
                            $stats['pending']++;
                        }
                    }
                }

                $stats['processed']++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        return $stats;
    }
}
