<?php

namespace App\Jobs\Outline;

use App\Models\ActionProgress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateDayNoteProgress implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $progressId,
        public int $monthNumber,
        public int $daysInMonth,
        public bool $isFinalMonth = false
    ) {}

    public function handle(): void
    {
        $progress = ActionProgress::find($this->progressId);

        if (! $progress) {
            return;
        }

        $details = $progress->details ?? [];
        $details['completed_months'] = $this->monthNumber;
        $details['completed_days'] = ($details['completed_days'] ?? 0) + $this->daysInMonth;

        $progress->update(['details' => $details]);

        // Mark as completed if this is the final month
        if ($this->isFinalMonth) {
            $progress->markCompleted();
        }
    }
}
