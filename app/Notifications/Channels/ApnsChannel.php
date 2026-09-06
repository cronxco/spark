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

    /**
     * Notification type -> UNNotificationCategory identifier registered by the
     * iOS client.
     *
     * Only failure-shaped notifications currently map: INTEGRATION_FAILED is the
     * one registered category with matching actions (VIEW, REAUTH). Types absent
     * from this map are sent without a category, which is a plain notification —
     * the honest outcome until either the client registers a matching category
     * or the type is retired. The client's other registered categories (ANOMALY,
     * DIGEST, NEW_BOOKMARK, CALENDAR_EVENT) have no server-side producer.
     *
     * @var array<string, string>
     */
    private const CLIENT_CATEGORIES = [
        'integration_failed' => 'INTEGRATION_FAILED',
        'integration_authentication_failed' => 'INTEGRATION_FAILED',
        'fetch_multiple_failures' => 'INTEGRATION_FAILED',
        'cookie_expiry_warning' => 'INTEGRATION_FAILED',
        'migration_failed' => 'INTEGRATION_FAILED',
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

        // The category identifier selects which UNNotificationCategory — and so
        // which action buttons — the client shows. It must be one the client
        // registered, and matching is case-sensitive. Sending the raw snake_case
        // notification type meant no category ever matched, leaving every
        // action button inert. threadId is a grouping key only, so the raw type
        // remains correct there.
        $category = $type === null ? null : (self::CLIENT_CATEGORIES[$type] ?? null);

        if ($message->category === null && $category !== null) {
            $message->category($category);
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

        $message->custom([
            ...$message->custom,
            'spark' => array_merge($envelope, $existing),
        ]);
    }

    /**
     * Dispatch a silent content-available push so the client can sync.
     */
    protected function sendSilentCompanion(Client $client, mixed $notifiable, Notification $notification, array $tokens): void
    {
        $silent = (new ApnMessage)
            ->contentAvailable(1)
            ->pushType(ApnMessagePushType::Background)
            ->custom([
                'spark' => array_filter([
                    'type' => method_exists($notification, 'getNotificationType')
                        ? $notification->getNotificationType()
                        : null,
                    'sync_cursor' => $notification->sparkSyncCursor ?? null,
                ], fn ($value) => $value !== null),
            ]);

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
