<?php

namespace App\Livewire\Money;

use App\Jobs\Money\ApplyAccountCleanup;
use App\Livewire\Concerns\ToleratesRepeatedLazyLoad;
use App\Models\ActionProgress;
use App\Services\Money\AccountCleanup;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use RuntimeException;

#[Lazy]
class DataHealth extends Component
{
    use ToleratesRepeatedLazyLoad;

    public ?string $progressId = null;

    /** @var array<string, string> */
    public array $categoryNames = [];

    public function placeholder(): string
    {
        return '<div class="p-6"><span class="loading loading-spinner"></span></div>';
    }

    public function saveCategoryName(string $categoryId): void
    {
        $name = trim($this->categoryNames[$categoryId] ?? '');
        if ($name === '' || mb_strlen($name) > 60) {
            $this->addError("categoryNames.{$categoryId}", 'Enter a name up to 60 characters.');

            return;
        }

        try {
            app(AccountCleanup::class)->setCategoryName((string) Auth::id(), $categoryId, $name);
        } catch (RuntimeException $e) {
            $this->addError("categoryNames.{$categoryId}", $e->getMessage());
        }
    }

    public function apply(string $fingerprint): void
    {
        $userId = (string) Auth::id();
        if ($this->isRunning()) {
            return;
        }

        $progress = ActionProgress::createProgress(
            userId: $userId,
            actionType: ApplyAccountCleanup::ACTION_TYPE,
            actionId: (string) Str::uuid(),
            step: 'queued',
            message: 'Waiting to apply the reviewed clean-up...',
            progress: 0,
        );
        $this->progressId = (string) $progress->id;

        ApplyAccountCleanup::dispatch($userId, $fingerprint, (string) $progress->id);
    }

    public function isRunning(): bool
    {
        $progress = $this->progress();

        return $progress !== null && $progress->isInProgress();
    }

    public function progress(): ?ActionProgress
    {
        if ($this->progressId === null) {
            return null;
        }

        return ActionProgress::where('user_id', Auth::id())->find($this->progressId);
    }

    public function render(): View
    {
        $cleanup = app(AccountCleanup::class);
        $plan = $cleanup->plan((string) Auth::id());
        $order = ['high' => 0, 'medium' => 1, 'low' => 2];
        $steps = collect($plan['steps'])
            ->map(fn ($step) => $step + $cleanup->describe($step))
            ->sortBy(fn ($step) => $order[$step['severity']] ?? 3)
            ->values()
            ->all();
        $audit = Auth::user()?->settings['money']['cleanup_audit'] ?? [];

        return view('livewire.money.data-health', [
            'steps' => $steps,
            'audit' => array_slice($audit, 0, 5),
            'fingerprint' => $plan['fingerprint'],
            'progress' => $this->progress(),
            'runnable' => collect($plan['steps'])->filter(fn ($step) => $step['blocked'] === null && ! $step['needs_input'])->count(),
        ]);
    }
}
