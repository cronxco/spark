<?php

use App\Models\Session;
use Carbon\Carbon;
use Jenssegers\Agent\Agent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;
use Mary\Traits\Toast;

new class extends Component {
    use Toast;

    public array $sessions = [];

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->loadSessions();
    }

    /**
     * Load user's sessions from database.
     */
    public function loadSessions(): void
    {
        $this->sessions = collect(
            DB::table('sessions')
                ->where('user_id', Auth::id())
                ->orderBy('last_activity', 'desc')
                ->get()
        )->map(function ($session) {
            $agent = new Agent();
            $agent->setUserAgent($session->user_agent ?? '');
            
            return [
                'id' => $session->id,
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_activity' => $session->last_activity,
                'is_current' => $session->id === session()->getId(),
                'device' => [
                    'platform' => $this->getPlatform($agent),
                    'browser' => $agent->browser() ?: 'Unknown',
                    'is_desktop' => $agent->isDesktop(),
                    'is_mobile' => $agent->isMobile(),
                    'is_tablet' => $agent->isTablet(),
                ],
                'device_name' => $this->getDeviceName($agent),
                'last_activity_human' => Carbon::createFromTimestamp($session->last_activity)->diffForHumans(),
            ];
        })->toArray();
    }

    /**
     * Revoke a specific session.
     */
    public function revokeSession(string $sessionId): void
    {
        if ($sessionId === session()->getId()) {
            $this->error('You cannot revoke your current session.');
            return;
        }

        DB::table('sessions')->where('id', $sessionId)->where('user_id', Auth::id())->delete();
        
        $this->loadSessions();
        $this->success('Session revoked successfully.');
    }

    /**
     * Revoke all other sessions except the current one.
     */
    public function revokeOtherSessions(): void
    {
        $deleted = DB::table('sessions')
            ->where('user_id', Auth::id())
            ->where('id', '!=', session()->getId())
            ->delete();
            
        $this->loadSessions();
        $this->success("Revoked {$deleted} other sessions successfully.");
    }

    /**
     * Get platform name from user agent.
     */
    protected function getPlatform(\Jenssegers\Agent\Agent $agent): string
    {
        if ($agent->isAndroidOS()) {
            return 'Android';
        }
        
        if ($agent->isIOS()) {
            return 'iOS';
        }
        
        if ($agent->isMac()) {
            return 'macOS';
        }
        
        if ($agent->isWindows()) {
            return 'Windows';
        }
        
        if ($agent->isLinux()) {
            return 'Linux';
        }
        
        return $agent->platform() ?: 'Unknown';
    }

    /**
     * Get device name from user agent.
     */
    protected function getDeviceName(\Jenssegers\Agent\Agent $agent): string
    {
        $platform = $this->getPlatform($agent);
        $browser = $agent->browser() ?: 'Unknown';
        
        if ($agent->isMobile()) {
            return "$platform Mobile - $browser";
        }
        
        if ($agent->isTablet()) {
            return "$platform Tablet - $browser";
        }
        
        return "$platform Desktop - $browser";
    }
}; ?>

<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <x-card title="{{ __('Browser Sessions') }}" shadow>
            <div class="space-y-6">
                <div class="text-sm text-base-content/70">
                    {{ __('Manage and logout active sessions on other browsers and devices.') }}
                </div>

                @if (count($sessions) === 0)
                    <div class="text-center py-8">
                        <div class="text-base-content/50">
                            <i class="fas fa-desktop text-4xl mb-4"></i>
                            <p>{{ __('No active sessions found.') }}</p>
                        </div>
                    </div>
                @else
                    <div class="space-y-4">
                        @foreach ($sessions as $session)
                            <div class="flex items-center justify-between p-4 bg-base-200 rounded-lg">
                                <div class="flex items-center space-x-4">
                                    <div class="flex-shrink-0">
                                        @if ($session['device']['is_mobile'])
                                            <i class="fas fa-mobile-alt text-2xl text-primary"></i>
                                        @elseif ($session['device']['is_tablet'])
                                            <i class="fas fa-tablet-alt text-2xl text-primary"></i>
                                        @else
                                            <i class="fas fa-desktop text-2xl text-primary"></i>
                                        @endif
                                    </div>

                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center space-x-2">
                                            <h4 class="text-lg font-medium truncate">
                                                {{ $session['device_name'] }}
                                            </h4>
                                            @if ($session['is_current'])
                                                <x-badge value="{{ __('This Device') }}" class="badge-primary badge-sm" />
                                            @endif
                                        </div>
                                        
                                        <div class="text-sm text-base-content/70 space-y-1">
                                            <p>
                                                <i class="fas fa-map-marker-alt mr-1"></i>
                                                {{ $session['ip_address'] }}
                                            </p>
                                            <p>
                                                <i class="fas fa-clock mr-1"></i>
                                                {{ __('Last active') }} {{ $session['last_activity_human'] }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                @if (!$session['is_current'])
                                    <div class="flex-shrink-0">
                                        <x-button
                                            label="{{ __('Revoke') }}"
                                            wire:click="revokeSession('{{ $session['id'] }}')"
                                            class="btn-outline btn-error btn-sm"
                                            spinner="revokeSession('{{ $session['id'] }}')"
                                            confirm="Are you sure you want to revoke this session?"
                                        />
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @if (count($sessions) > 1)
                        <div class="pt-6 border-t border-base-300">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h4 class="text-lg font-medium">{{ __('Other Sessions') }}</h4>
                                    <p class="text-sm text-base-content/70">
                                        {{ __('Revoke all other browser sessions across all of your devices.') }}
                                    </p>
                                </div>

                                <x-button
                                    label="{{ __('Revoke All Other Sessions') }}"
                                    wire:click="revokeOtherSessions"
                                    class="btn-outline btn-error"
                                    spinner="revokeOtherSessions"
                                    confirm="Are you sure you want to revoke all other sessions? This will log you out of all other devices."
                                />
                            </div>
                        </div>
                    @endif
                @endif
            </div>
        </x-card>
    </div>
</div>