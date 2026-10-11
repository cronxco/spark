<?php

namespace App\Jobs\Money;

use App\Models\ActionProgress;
use App\Services\Money\AccountCleanup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class ApplyAccountCleanup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ACTION_TYPE = 'money_cleanup';

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public string $userId,
        public string $fingerprint,
        public string $progressId,
    ) {}

    public function handle(AccountCleanup $cleanup): void
    {
        $progress = ActionProgress::find($this->progressId);
        $progress?->updateProgress('applying', 'Applying the reviewed clean-up...', 10);

        try {
            $result = $cleanup->apply($this->userId, $this->fingerprint);
        } catch (RuntimeException $e) {
            $progress?->markFailed($e->getMessage());

            return;
        }

        $progress?->markCompleted([
            'applied' => count($result['applied']),
            'skipped' => count($result['skipped']),
            'summary' => array_map(fn ($step) => $step['label'], $result['applied']),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        ActionProgress::find($this->progressId)?->markFailed($exception->getMessage());
    }
}
