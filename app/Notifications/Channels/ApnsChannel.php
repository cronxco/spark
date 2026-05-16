<?php

namespace App\Notifications\Channels;

use App\Models\PushSubscription;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use NotificationChannels\Apn\ApnAdapter;
use NotificationChannels\Apn\ApnMessage;
use NotificationChannels\Apn\ApnMessagePushType;
use Pushok\Client;
use Pushok\Response;

class ApnsChannel
{
    private const PERMANENT_FAILURES = [
        'BadDeviceToken',
        'DeviceTokenNotForTopic',
        'TopicDisallowed',
        'Unregistered',
    ];

    public function __construct(
        protected Client $client,
        protected Dispatcher $events,
        protected ApnAdapter $adapter,
    ) {}

    /**
     * Send the notification to Apple Push Notification Service.
     *
     * @return array<int, Response>|null
     */
    public function send(mixed $notifiable, Notification $notification): ?array
    {
        if (! method_exists($notification, 'toApn')) {
            return null;
        }

        $targetEnvironment = config('broadcasting.connections.apn.production')
            ? 'production'
            : 'sandbox';

        $subscriptions = $notifiable->pushSubscriptions()
            ->apns()
            ->where(function ($query) use ($targetEnvironment) {
                $query
                    ->where('app_environment', $targetEnvironment)
                    ->orWhereNull('app_environment');
            })
            ->get();

        if ($subscriptions->isEmpty()) {
            return null;
        }

        $totalIosSubscriptions = $notifiable->pushSubscriptions()->apns()->count();
        if ($totalIosSubscriptions > $subscriptions->count()) {
            Log::info('Skipped APNs subscriptions for non-target environment', [
                'target_environment' => $targetEnvironment,
                'selected' => $subscriptions->count(),
                'total_ios' => $totalIosSubscriptions,
            ]);
        }

        $message = $notification->toApn($notifiable);

        $this->applySparkEnvelope($message, $notification);

        $tokens = $subscriptions->pluck('endpoint')->all();

        $client = $message->client ?? $this->client;

        $responses = $this->sendNotifications($client, $message, $tokens);

        $this->dispatchEvents($notifiable, $notification, $responses);

        $this->sendSilentCompanion($client, $notifiable, $notification, $tokens);

        return $responses;
    }

    /**
     * Apply the Spark envelope defaults to an outgoing message.
     */
    protected function applySparkEnvelope(ApnMessage $message, Notification $notification): void
    {
        $type = method_exists($notification, 'getNotificationType')
            ? $notification->getNotificationType()
            : null;

        if ($message->category === null && $type !== null) {
            $message->category($type);
        }

        if ($message->threadId === null && $type !== null) {
            $message->threadId($type);
        }

        $envelope = array_filter([
            'type' => $type,
            'entity_type' => $notification->sparkEntityType ?? null,
            'entity_id' => $notification->sparkEntityId ?? null,
            'deep_link' => $notification->sparkDeepLink ?? null,
            'sync_cursor' => $notification->sparkSyncCursor ?? null,
        ], fn ($value) => $value !== null);

        if ($envelope === []) {
            return;
        }

        $existing = $message->custom['spark'] ?? [];

        $message->custom('spark', array_merge($envelope, $existing));
    }

    /**
     * Dispatch a silent content-available push so the client can sync.
     */
    protected function sendSilentCompanion(Client $client, mixed $notifiable, Notification $notification, array $tokens): void
    {
        $silent = (new ApnMessage)
            ->contentAvailable(1)
            ->pushType(ApnMessagePushType::Background)
            ->custom('spark', array_filter([
                'type' => method_exists($notification, 'getNotificationType')
                    ? $notification->getNotificationType()
                    : null,
                'sync_cursor' => $notification->sparkSyncCursor ?? null,
            ], fn ($value) => $value !== null));

        foreach ($tokens as $token) {
            $client->addNotification($this->adapter->adapt($silent, $token));
        }

        $responses = $client->push();

        $this->dispatchEvents($notifiable, $notification, $responses);
    }

    /**
     * @return array<int, Response>
     */
    protected function sendNotifications(Client $client, ApnMessage $message, array $tokens): array
    {
        foreach ($tokens as $token) {
            $client->addNotification($this->adapter->adapt($message, $token));
        }

        return $client->push();
    }

    protected function dispatchEvents(mixed $notifiable, Notification $notification, array $responses): void
    {
        foreach ($responses as $response) {
            if ($response->getStatusCode() === Response::APNS_SUCCESS) {
                continue;
            }

            $this->events->dispatch(new NotificationFailed(
                $notifiable,
                $notification,
                static::class,
                [
                    'id' => $response->getApnsId(),
                    'token' => $response->getDeviceToken(),
                    'error' => $response->getErrorReason(),
                ],
            ));

            $reason = $response->getErrorReason();
            Log::warning('APNs delivery failed', [
                'apns_id' => $response->getApnsId(),
                'reason' => $reason,
                'environment' => config('broadcasting.connections.apn.production') ? 'production' : 'sandbox',
                'bundle_id' => config('broadcasting.connections.apn.app_bundle_id'),
                'notification_type' => method_exists($notification, 'getNotificationType')
                    ? $notification->getNotificationType()
                    : get_class($notification),
            ]);

            if (in_array($reason, self::PERMANENT_FAILURES, true)) {
                PushSubscription::query()
                    ->apns()
                    ->where('endpoint', $response->getDeviceToken())
                    ->delete();
            }
        }
    }
}
