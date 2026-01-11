<?php

namespace App\Livewire;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Models\IntegrationGroup;
use Exception;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class GoCardlessReconfirmBanner extends Component
{
    public IntegrationGroup $group;

    public bool $loading = false;

    public function mount(IntegrationGroup $group): void
    {
        $this->group = $group;
    }

    public function attemptReconfirmation()
    {
        $this->loading = true;

        try {
            $plugin = app(GoCardlessBankPlugin::class);
            $reconfirmation = $plugin->attemptReconfirmation($this->group);

            Log::info('GoCardlessReconfirmBanner: Reconfirmation initiated', [
                'group_id' => $this->group->id,
                'reconfirmation_url' => $reconfirmation['reconfirmation_url'] ?? 'missing',
            ]);

            // Redirect user to reconfirmation URL
            return redirect($reconfirmation['reconfirmation_url']);
        } catch (Exception $e) {
            Log::info('GoCardlessReconfirmBanner: Reconfirmation not available, falling back to new EUA', [
                'group_id' => $this->group->id,
                'error' => $e->getMessage(),
            ]);

            // Reconfirmation not available, fall back to new EUA
            $this->loading = false;

            return $this->createNewEua();
        }
    }

    public function createNewEua()
    {
        $this->loading = true;

        // Get the institution ID from the group metadata
        $institutionId = $this->group->auth_metadata['institution_id'] ?? null;

        if (! $institutionId) {
            $this->addError('general', 'Cannot determine bank institution. Please contact support.');
            $this->loading = false;

            return;
        }

        try {
            $plugin = app(GoCardlessBankPlugin::class);
            $result = $plugin->createNewEuaAndRequisition($this->group, $institutionId);

            Log::info('GoCardlessReconfirmBanner: New EUA created', [
                'group_id' => $this->group->id,
                'requisition_id' => $result['requisition']['id'] ?? 'missing',
                'link' => $result['link'] ?? 'missing',
            ]);

            // Redirect user to authorization link
            return redirect($result['link']);
        } catch (Exception $e) {
            Log::error('GoCardlessReconfirmBanner: Failed to create new authorization', [
                'group_id' => $this->group->id,
                'error' => $e->getMessage(),
            ]);

            $this->addError('general', 'Failed to create new authorization: ' . $e->getMessage());
            $this->loading = false;
        }
    }

    public function render()
    {
        return view('livewire.go-cardless-reconfirm-banner');
    }
}
