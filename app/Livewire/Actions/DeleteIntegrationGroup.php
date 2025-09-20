<?php

namespace App\Livewire\Actions;

use App\Jobs\DeleteIntegrationGroupJob;
use App\Models\ActionProgress;
use App\Models\IntegrationGroup;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Mary\Traits\Toast;

class DeleteIntegrationGroup extends Component
{
    use Toast;

    public bool $showModal = false;
    public ?string $groupId = null;
    public ?IntegrationGroup $group = null;
    public array $deletionSummary = [
        'integrations' => 0,
        'events' => 0,
        'blocks' => 0,
        'objects' => 0,
        'service_name' => '',
        'account_id' => null,
    ];

    #[Validate('required|string')]
    public string $confirmationText = '';

    public bool $finalConfirmation = false;
    public int $step = 1;
    public bool $isDeleting = false;
    public bool $showProgress = false;
    public string $progressMessage = '';
    public int $progressPercentage = 0;
    public string $progressStep = '';
    public array $progressDetails = [];

    protected $listeners = ['confirmDeleteGroup'];

    public function mount(): void
    {
        // Initialize component
    }

    public function confirmDeleteGroup(string $groupId): void
    {
        $this->groupId = $groupId;
        $this->group = IntegrationGroup::where('id', $groupId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $this->loadDeletionSummary();
        $this->resetConfirmation();
        $this->showModal = true;
    }

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->step = 2;
        } elseif ($this->step === 2) {
            if ($this->validateConfirmationText()) {
                $this->step = 3;
            }
        }
    }

    public function previousStep(): void
    {
        if ($this->step > 1) {
            $this->step--;
        }
    }

    public function deleteGroup(): void
    {
        if (! $this->finalConfirmation) {
            $this->error('Please confirm that you understand this action cannot be undone.');

            return;
        }

        if ($this->step !== 3) {
            $this->error('Please complete all confirmation steps before proceeding.');

            return;
        }

        if (! $this->group) {
            $this->error('Invalid integration group. Please refresh the page and try again.');

            return;
        }

        $this->isDeleting = true;

        try {
            DeleteIntegrationGroupJob::dispatch($this->groupId, Auth::id());

            // Show progress modal instead of closing
            $this->showProgress = true;
            $this->progressMessage = 'Deletion started...';
            $this->progressPercentage = 0;
            $this->progressStep = 'starting';

        } catch (Exception $e) {
            Log::error('Failed to dispatch integration group deletion job', [
                'group_id' => $this->groupId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            $this->error('Failed to start deletion process. Please try again or contact support if the problem persists.');
            $this->isDeleting = false;
        }
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->showProgress = false;
        $this->resetConfirmation();
        $this->step = 1;
        $this->isDeleting = false;
    }

    public function checkProgress(): void
    {
        if (! $this->groupId || ! $this->showProgress) {
            return;
        }

        try {
            // Get the latest progress record for this deletion action
            $progress = ActionProgress::getLatestProgress(
                Auth::id(),
                'deletion',
                $this->groupId
            );

            if (! $progress) {
                // No progress record found, might be completed or failed
                $this->handleDeletionComplete();

                return;
            }

            // Update progress properties
            $this->progressStep = $progress->step;
            $this->progressMessage = $progress->message;
            $this->progressPercentage = $progress->progress;
            $this->progressDetails = $progress->details ?? [];

            if ($progress->isCompleted()) {
                $this->handleDeletionComplete();
            } elseif ($progress->isFailed()) {
                $this->handleDeletionFailed($progress->error_message ?? 'Unknown error');
            }
        } catch (Exception $e) {
            Log::error('Failed to check deletion progress', [
                'group_id' => $this->groupId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function handleDeletionComplete(): void
    {
        $this->success('Integration group deleted successfully!');
        $this->closeModal();

        // Refresh the integrations page data
        $this->dispatch('$refresh');
    }

    public function handleDeletionFailed(string $error): void
    {
        $this->error('Deletion failed: ' . $error);
        $this->showProgress = false;
        $this->isDeleting = false;
    }

    public function validateConfirmationText(): bool
    {
        if (! $this->group) {
            return false;
        }

        $expectedText = strtolower($this->group->service);
        $providedText = strtolower(trim($this->confirmationText));

        return $expectedText === $providedText;
    }

    public function render()
    {
        return view('livewire.actions.delete-integration-group');
    }

    private function loadDeletionSummary(): void
    {
        $this->deletionSummary = $this->group->getDeletionSummary();
    }

    private function resetConfirmation(): void
    {
        $this->confirmationText = '';
        $this->finalConfirmation = false;
        $this->step = 1;
    }
}
