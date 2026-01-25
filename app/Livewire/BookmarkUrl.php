<?php

namespace App\Livewire;

use App\Integrations\PluginRegistry;
use App\Jobs\Fetch\FetchSingleUrl;
use App\Models\EventObject;
use App\Models\Integration;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class BookmarkUrl extends Component
{
    public bool $showModal = false;

    public string $url = '';

    public string $fetchMode = 'recurring';

    public bool $enabled = true;

    protected array $rules = [
        'url' => 'required|url|max:2048',
        'fetchMode' => 'required|in:once,recurring',
        'enabled' => 'boolean',
    ];

    #[On('bookmark-url')]
    public function handleBookmarkUrl(string $url = '', string $mode = 'recurring'): void
    {
        // Pre-fill URL and show modal
        $this->url = $url;
        $this->fetchMode = $mode;
        $this->enabled = true;
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate();

        // Get or create Fetch integration for this user
        $fetchIntegration = $this->getFetchIntegration();

        if (! $fetchIntegration) {
            $this->addError('url', 'Fetch integration not found. Please set up Fetch first.');

            return;
        }

        // Normalize URL
        $normalizedUrl = $this->normalizeUrl($this->url);

        // Get domain for title
        $domain = $this->getDomainFromUrl($normalizedUrl);

        // Check if URL already exists
        $existingWebpage = EventObject::where('user_id', Auth::id())
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->where('url', $normalizedUrl)
            ->first();

        if ($existingWebpage) {
            // Update existing webpage
            $metadata = $existingWebpage->metadata ?? [];
            $metadata['fetch_integration_id'] = $fetchIntegration->id;
            $metadata['fetch_mode'] = $this->fetchMode;
            $metadata['enabled'] = $this->enabled;

            $existingWebpage->metadata = $metadata;
            $existingWebpage->save();

            $webpageId = $existingWebpage->id;
        } else {
            // Create new webpage EventObject
            $webpage = EventObject::create([
                'user_id' => Auth::id(),
                'concept' => 'bookmark',
                'type' => 'fetch_webpage',
                'title' => $domain,
                'url' => $normalizedUrl,
                'time' => now(),
                'metadata' => [
                    'fetch_integration_id' => $fetchIntegration->id,
                    'fetch_mode' => $this->fetchMode,
                    'enabled' => $this->enabled,
                    'fetch_count' => 0,
                    'added_via' => 'spotlight',
                ],
            ]);

            $webpageId = $webpage->id;
        }

        // Dispatch fetch job immediately
        FetchSingleUrl::dispatch($fetchIntegration, $webpageId, $normalizedUrl);

        // Close modal and notify
        $this->showModal = false;
        $this->url = '';
        $this->dispatch('url-bookmarked');
        $this->dispatch('notify', message: 'URL bookmarked successfully and fetch queued!', type: 'success');
    }

    public function cancel(): void
    {
        $this->showModal = false;
        $this->url = '';
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.bookmark-url');
    }

    /**
     * Get or find the Fetch integration for the authenticated user.
     */
    private function getFetchIntegration(): ?Integration
    {
        // Find Fetch plugin
        $fetchPlugin = PluginRegistry::getPlugin('fetch');
        if (! $fetchPlugin) {
            return null;
        }

        // Find active Fetch integration for this user (not paused)
        $integrations = Integration::where('user_id', Auth::id())
            ->where('service', 'fetch')
            ->get();

        // Filter out paused integrations
        return $integrations->first(fn ($integration) => ! $integration->isPaused());
    }

    /**
     * Normalize URL by adding protocol if missing.
     */
    private function normalizeUrl(string $url): string
    {
        if (! preg_match('~^(?:f|ht)tps?://~i', $url)) {
            return 'https://' . $url;
        }

        return $url;
    }

    /**
     * Extract domain from URL.
     */
    private function getDomainFromUrl(string $url): string
    {
        if (function_exists('get_domain_from_url')) {
            return get_domain_from_url($url) ?? parse_url($url, PHP_URL_HOST) ?? 'Unknown';
        }

        return parse_url($url, PHP_URL_HOST) ?? 'Unknown';
    }
}
