<?php

namespace App\Jobs\Money;

use App\Models\ActionProgress;
use App\Models\User;
use App\Services\Money\AccountCleanup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

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

        $this->audit($result);

        $progress?->markCompleted([
            'applied' => count($result['applied']),
            'skipped' => count($result['skipped']),
            'summary' => array_map(fn ($step) => $step['label'], $result['applied']),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        ActionProgress::find($this->progressId)?->markFailed($exception->getMessage());
    }

    /**
     * Keeps the last 20 applied clean-ups in the user's settings and in the log, so what changed and
     * when can be checked later. Nothing here needs a table.
     *
     * @param  array{applied: list<array<string, mixed>>, skipped: list<array<string, mixed>>}  $result
     */
    private function audit(array $result): void
    {
        $entry = [
            'applied_at' => now()->toIso8601String(),
            'fingerprint' => $this->fingerprint,
            'progress_id' => $this->progressId,
            'applied' => array_map(fn ($step) => ['key' => $step['key'], 'label' => $step['label']], $result['applied']),
            'skipped' => array_map(fn ($step) => ['key' => $step['key'], 'label' => $step['label']], $result['skipped']),
        ];
        Log::info('Money clean-up applied', ['user_id' => $this->userId] + $entry);

        $user = User::find($this->userId);
        if ($user === null) {
            return;
        }
        $settings = $user->settings ?? [];
        $settings['money']['cleanup_audit'] = array_slice(array_merge([$entry], $settings['money']['cleanup_audit'] ?? []), 0, 20);
        $user->update(['settings' => $settings]);
    }
}
