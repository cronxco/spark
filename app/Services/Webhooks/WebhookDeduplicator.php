<?php

namespace App\Services\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Drops provider retries of a webhook Spark has already queued (decision
 * I-7: cache only, no receipts table). A delivery the provider names is
 * remembered for a day; one without an id is matched on its exact body for
 * a few minutes, long enough to catch retries without swallowing a real
 * repeat later on.
 */
class WebhookDeduplicator
{
    public const DELIVERY_ID_TTL_SECONDS = 86_400;

    public const BODY_HASH_TTL_SECONDS = 300;

    /** Headers providers use to name a delivery, checked in order. */
    private const DELIVERY_ID_HEADERS = ['X-GitHub-Delivery', 'Webhook-Id', 'Idempotency-Key', 'X-Request-Id'];

    /**
     * Claim the delivery. Returns the cache key when this is the first time
     * Spark has seen it, or null when it is a duplicate.
     */
    public function claim(Request $request, string $service, string $secret): ?string
    {
        [$identity, $ttl] = $this->identity($request);
        $key = 'webhook:seen:' . hash('sha256', $service . '|' . $secret . '|' . $identity);

        return Cache::add($key, now()->toJSON(), $ttl) ? $key : null;
    }

    /** Let the provider's retry through after Spark failed to queue the delivery. */
    public function release(string $key): void
    {
        Cache::forget($key);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function identity(Request $request): array
    {
        foreach (self::DELIVERY_ID_HEADERS as $header) {
            $id = $request->header($header);
            if (is_string($id) && $id !== '') {
                return ["header:{$header}:{$id}", self::DELIVERY_ID_TTL_SECONDS];
            }
        }

        $eventId = $request->input('event_id');
        if (is_string($eventId) && $eventId !== '') {
            return ["event:{$eventId}", self::DELIVERY_ID_TTL_SECONDS];
        }

        return ['body:' . hash('sha256', $request->getContent()), self::BODY_HASH_TTL_SECONDS];
    }
}
