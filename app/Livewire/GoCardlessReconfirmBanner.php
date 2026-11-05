<?php

namespace App\Livewire;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Models\IntegrationGroup;
use Exception;
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
        return $this->createNewEua();
    }

    public function createNewEua()
    {
        abort_unless($this->group->user_id === auth()->id() && $this->group->service === 'gocardless', 403);
        $this->loading = true;
        try {
            return redirect(app(GoCardlessBankPlugin::class)->getOAuthUrl($this->group));
        } catch (Exception $e) {
            $this->addError('general', $e->getMessage());
            $this->loading = false;
        }
    }

    public function render()
    {
        return view('livewire.go-cardless-reconfirm-banner');
    }
}
