<?php

namespace App\Jobs\Data\Receipt;

use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupOldReceiptEmailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes for cleanup

    public $tries = 2;

    public function handle(): void
    {
        Log::info('Receipt: Starting cleanup of old email files from S3');

        try {
            $disk = Storage::disk('s3-receipts');
            $retentionDays = config('services.receipt.retention_days', 30);
            $cutoffDate = Carbon::now()->subDays($retentionDays);

            // List all files in the bucket
            $allFiles = $disk->allFiles();
            $deletedCount = 0;
            $errorCount = 0;

            foreach ($allFiles as $file) {
                try {
                    $lastModified = Carbon::createFromTimestamp($disk->lastModified($file));

                    if ($lastModified->lt($cutoffDate)) {
                        $disk->delete($file);
                        $deletedCount++;

                        Log::debug('Receipt: Deleted old email file', [
                            'file' => $file,
                            'last_modified' => $lastModified->toIso8601String(),
                        ]);
                    }
                } catch (Exception $e) {
                    $errorCount++;
                    Log::warning('Receipt: Failed to process file during cleanup', [
                        'file' => $file,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('Receipt: Cleanup completed', [
                'total_files' => count($allFiles),
                'deleted_count' => $deletedCount,
                'error_count' => $errorCount,
                'cutoff_date' => $cutoffDate->toIso8601String(),
            ]);
        } catch (Exception $e) {
            Log::error('Receipt: Cleanup failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
