<?php

use App\Models\PushSubscription;
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
    public array $mobileDevices = [];

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->loadSessions();
        $this->loadMobileDevices();
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
     * Load registered iOS mobile devices.
     */
    public function loadMobileDevices(): void
    {
        $this->mobileDevices = Auth::user()
            ->pushSubscriptions()
            ->apns()
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (PushSubscription $device) => [
                'id' => $device->id,
                'os_version' => $device->os_version,
                'app_version' => $device->app_version,
                'app_environment' => $device->app_environment,
                'last_active' => $device->updated_at?->diffForHumans(),
                'registered' => $device->created_at?->diffForHumans(),
            ])
            ->toArray();
    }

    /**
     * Remove a registered mobile device.
     */
    public function revokeMobileDevice(int $id): void
    {
        $deleted = Auth::user()->pushSubscriptions()->where('id', $id)->delete();

        if ($deleted) {
            $this->loadMobileDevices();
            $this->success('Mobile device removed successfully.');
        } else {
            $this->error('Device not found.');
        }
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

<div>
    <x-header title="{{ __('Browser Sessions') }}" subtitle="{{ __('Manage and log out your active sessions on other browsers and devices') }}" separator>
        <x-slot:actions>
            @if (count($sessions) > 1)
                <x-button
                    label="{{ __('Revoke All Others') }}"
                    wire:click="revokeOtherSessions"
                    class="btn-error btn-sm"
                    spinner="revokeOtherSessions"
                    confirm="{{ __('Are you sure you want to revoke all other sessions? This will log you out of all other devices.') }}"
                />
            @endif
        </x-slot:actions>
    </x-header>

    <div class="space-y-4 lg:space-y-6">
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <h3 class="text-lg font-semibold mb-4">{{ __('Active Sessions') }}</h3>

            @if (count($sessions) === 0)
                <div class="text-center py-8">
                    <div class="text-base-content/50">
                        <i class="fas fa-desktop text-4xl mb-4"></i>
                        <p>{{ __('No active sessions found.') }}</p>
                    </div>
                </div>
            @else
                <div class="space-y-3">
                    @foreach ($sessions as $session)
                        <div class="border border-base-300 bg-base-100 rounded-lg p-4 @if ($session['is_current']) border-2 border-primary/20 @endif">
                            <div class="flex items-center justify-between">
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
                                        <div class="flex items-center space-x-2 mb-1">
                                            <h4 class="font-medium truncate">
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
                                            confirm="{{ __('Are you sure you want to revoke this session?') }}"
                                        />
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
    <!-- Mobile Devices Card -->
    <div class="card bg-base-200 shadow">
        <div class="card-body">
            <h3 class="text-lg font-semibold mb-4">{{ __('Mobile Devices') }}</h3>

            @if (count($mobileDevices) === 0)
                <div class="text-center py-8">
                    <div class="text-base-content/50">
                        <x-icon name="fab.apple" class="w-10 h-10 mx-auto mb-4" />
                        <p>{{ __('No iOS devices registered.') }}</p>
                    </div>
                </div>
            @else
                <div class="space-y-3">
                    @foreach ($mobileDevices as $device)
                        <div class="border border-base-300 bg-base-100 rounded-lg p-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-4">
                                    <div class="flex-shrink-0">
                                        <x-icon name="fab.apple" class="w-6 h-6 text-primary" />
                                    </div>

                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 mb-1">
                                            <h4 class="font-medium">
                                                {{ $device['os_version'] ?? 'iOS Device' }}
                                            </h4>
                                            @if (($device['app_environment'] ?? '') === 'sandbox')
                                                <x-badge value="{{ __('Sandbox') }}" class="badge-warning badge-sm" />
                                            @endif
                                        </div>

                                        <div class="text-sm text-base-content/70 space-y-1">
                                            @if ($device['app_version'])
                                                <p>
                                                    <x-icon name="fas.mobile-screen" class="w-3 h-3 inline mr-1" />
                                                    {{ __('App version') }} {{ $device['app_version'] }}
                                                </p>
                                            @endif
                                            <p>
                                                <x-icon name="fas.clock" class="w-3 h-3 inline mr-1" />
                                                {{ __('Last seen') }} {{ $device['last_active'] }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex-shrink-0">
                                    <x-button
                                        label="{{ __('Remove') }}"
                                        wire:click="revokeMobileDevice({{ $device['id'] }})"
                                        class="btn-outline btn-error btn-sm"
                                        spinner="revokeMobileDevice({{ $device['id'] }})"
                                        confirm="{{ __('Remove this iOS device? It will stop receiving push notifications.') }}"
                                    />
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
    </div>
</div>