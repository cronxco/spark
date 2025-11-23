<?php

use App\Models\User;
use App\Notifications\AdminPushNotification;
use Illuminate\Support\Facades\Log;
use Livewire\Volt\Component;
use Mary\Traits\Toast;
use NotificationChannels\WebPush\PushSubscription;

use function Livewire\Volt\layout;

layout('components.layouts.app');

new class extends Component
{
    use Toast;

    public string $title = '';
    public string $message = '';
    public string $url = '';
    public bool $confirmSend = false;

    protected function rules(): array
    {
        return [
            'title' => 'required|string|max:100',
            'message' => 'required|string|max:500',
            'url' => 'nullable|url|max:500',
        ];
    }

    public function getSubscribedUsersProperty()
    {
        return User::whereHas('pushSubscriptions')
            ->withCount('pushSubscriptions')
            ->orderBy('email')
            ->get();
    }

    public function getTotalSubscriptionsProperty(): int
    {
        return PushSubscription::count();
    }

    public function sendNotification(): void
    {
        $this->validate();

        if (!$this->confirmSend) {
            $this->confirmSend = true;
            return;
        }

        $users = User::whereHas('pushSubscriptions')->get();

        if ($users->isEmpty()) {
            $this->error('No users have push subscriptions.');
            return;
        }

        $notification = new AdminPushNotification(
            $this->title,
            $this->message,
            $this->url ?: null
        );

        $successCount = 0;
        $failCount = 0;

        foreach ($users as $user) {
            try {
                $user->notifyNow($notification);
                $successCount++;
            } catch (\Exception $e) {
                $failCount++;
                Log::error("Failed to send push to {$user->email}: " . $e->getMessage());
            }
        }

        $this->reset(['title', 'message', 'url', 'confirmSend']);

        if ($failCount > 0) {
            $this->warning("Sent to {$successCount} users, {$failCount} failed.");
        } else {
            $this->success("Notification sent to {$successCount} users!");
        }
    }

    public function cancelSend(): void
    {
        $this->confirmSend = false;
    }

    public function sendToUser(string $userId): void
    {
        $this->validate();

        $user = User::find($userId);

        if (!$user) {
            $this->error('User not found.');
            return;
        }

        try {
            $notification = new AdminPushNotification(
                $this->title,
                $this->message,
                $this->url ?: null
            );

            $user->notifyNow($notification);
            $this->success("Notification sent to {$user->email}!");
        } catch (\Exception $e) {
            $this->error("Failed: " . $e->getMessage());
        }
    }
}; ?>

<div>
    <x-header title="Push Notifications" subtitle="Send push notifications to users" separator />

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Compose Notification --}}
        <div class="lg:col-span-2">
            <div class="card bg-base-200 shadow">
                <div class="card-body">
                    <h3 class="text-lg font-semibold mb-4">Compose Notification</h3>

                    <div class="space-y-4">
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">Title</span>
                                <span class="label-text-alt text-base-content/50">{{ strlen($title) }}/100</span>
                            </label>
                            <input
                                type="text"
                                wire:model.live="title"
                                class="input input-bordered w-full"
                                placeholder="Notification title"
                                maxlength="100"
                            />
                            @error('title')
                                <label class="label">
                                    <span class="label-text-alt text-error">{{ $message }}</span>
                                </label>
                            @enderror
                        </div>

                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">Message</span>
                                <span class="label-text-alt text-base-content/50">{{ strlen($message) }}/500</span>
                            </label>
                            <textarea
                                wire:model.live="message"
                                class="textarea textarea-bordered w-full"
                                placeholder="Notification message"
                                rows="3"
                                maxlength="500"
                            ></textarea>
                            @error('message')
                                <label class="label">
                                    <span class="label-text-alt text-error">{{ $message }}</span>
                                </label>
                            @enderror
                        </div>

                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">URL (optional)</span>
                            </label>
                            <input
                                type="url"
                                wire:model.live="url"
                                class="input input-bordered w-full"
                                placeholder="https://example.com/page"
                            />
                            @error('url')
                                <label class="label">
                                    <span class="label-text-alt text-error">{{ $message }}</span>
                                </label>
                            @enderror
                        </div>

                        {{-- Preview --}}
                        @if ($title || $message)
                            <div class="divider">Preview</div>
                            <div class="bg-base-300 rounded-lg p-4">
                                <div class="flex items-start gap-3">
                                    <img src="/apple-touch-icon.png" alt="Icon" class="w-10 h-10 rounded" />
                                    <div class="flex-1 min-w-0">
                                        <div class="font-semibold text-sm">{{ $title ?: 'Notification Title' }}</div>
                                        <div class="text-sm text-base-content/70 line-clamp-2">{{ $message ?: 'Notification message will appear here...' }}</div>
                                        @if ($url)
                                            <div class="text-xs text-primary mt-1 truncate">{{ $url }}</div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endif

                        {{-- Send Button --}}
                        <div class="flex items-center justify-end gap-2 pt-4">
                            @if ($confirmSend)
                                <span class="text-warning text-sm mr-2">
                                    Send to {{ $this->subscribedUsers->count() }} users?
                                </span>
                                <button
                                    wire:click="cancelSend"
                                    class="btn btn-ghost"
                                >
                                    Cancel
                                </button>
                                <button
                                    wire:click="sendNotification"
                                    class="btn btn-warning"
                                    wire:loading.attr="disabled"
                                >
                                    <span wire:loading wire:target="sendNotification" class="loading loading-spinner loading-sm"></span>
                                    Confirm Send
                                </button>
                            @else
                                <button
                                    wire:click="sendNotification"
                                    class="btn btn-primary"
                                    wire:loading.attr="disabled"
                                    @if (!$title || !$message) disabled @endif
                                >
                                    <x-icon name="fas.paper-plane" class="w-4 h-4" />
                                    Send to All Users
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Stats & Users --}}
        <div class="space-y-6">
            {{-- Stats --}}
            <div class="card bg-base-200 shadow">
                <div class="card-body">
                    <h3 class="text-lg font-semibold mb-4">Statistics</h3>
                    <div class="stats stats-vertical shadow">
                        <div class="stat">
                            <div class="stat-title">Users with Push</div>
                            <div class="stat-value text-primary">{{ $this->subscribedUsers->count() }}</div>
                        </div>
                        <div class="stat">
                            <div class="stat-title">Total Subscriptions</div>
                            <div class="stat-value">{{ $this->totalSubscriptions }}</div>
                            <div class="stat-desc">Across all devices</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Users List --}}
            <div class="card bg-base-200 shadow">
                <div class="card-body">
                    <h3 class="text-lg font-semibold mb-4">Subscribed Users</h3>

                    @if ($this->subscribedUsers->isEmpty())
                        <div class="text-center text-base-content/50 py-4">
                            No users have push subscriptions yet.
                        </div>
                    @else
                        <div class="space-y-2 max-h-96 overflow-y-auto">
                            @foreach ($this->subscribedUsers as $user)
                                <div class="flex items-center justify-between p-2 bg-base-100 rounded-lg">
                                    <div class="min-w-0 flex-1">
                                        <div class="font-medium text-sm truncate">{{ $user->email }}</div>
                                        <div class="text-xs text-base-content/50">
                                            {{ $user->push_subscriptions_count }} device(s)
                                        </div>
                                    </div>
                                    <button
                                        wire:click="sendToUser('{{ $user->id }}')"
                                        class="btn btn-ghost btn-xs"
                                        title="Send to this user only"
                                        @if (!$title || !$message) disabled @endif
                                    >
                                        <x-icon name="fas.paper-plane" class="w-3 h-3" />
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
