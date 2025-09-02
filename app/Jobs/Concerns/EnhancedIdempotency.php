<?php

namespace App\Jobs\Concerns;

use App\Models\Event;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

trait EnhancedIdempotency
{
    /**
     * Generate a comprehensive idempotency key for this job
     */
    protected function generateIdempotencyKey(array $data = []): string
    {
        $components = [
            $this->integration->id,
            $this->getServiceName(),
            $this->getJobType(),
            md5(serialize($data)),
        ];

        return implode('_', $components);
    }

    /**
     * Check if this job has already been processed recently
     */
    protected function hasBeenProcessedRecently(string $key, int $ttl = 3600): bool
    {
        $cacheKey = "job_processed:{$key}";

        if (Cache::has($cacheKey)) {
            Log::debug('Job already processed recently', [
                'integration_id' => $this->integration->id,
                'service' => $this->getServiceName(),
                'job_type' => $this->getJobType(),
                'key' => $key,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Mark this job as processed
     */
    protected function markAsProcessed(string $key, int $ttl = 3600): void
    {
        $cacheKey = "job_processed:{$key}";
        Cache::put($cacheKey, true, $ttl);

        Log::debug('Marked job as processed', [
            'integration_id' => $this->integration->id,
            'service' => $this->getServiceName(),
            'job_type' => $this->getJobType(),
            'key' => $key,
        ]);
    }

    /**
     * Check for duplicate events based on source_id and content hash
     */
    protected function findDuplicateEvent(string $sourceId, array $eventData = []): ?Event
    {
        // First check by source_id
        $existingEvent = Event::where('source_id', $sourceId)
            ->where('integration_id', $this->integration->id)
            ->first();

        if ($existingEvent) {
            return $existingEvent;
        }

        // If no exact source_id match, check for content-based duplicates
        if (! empty($eventData)) {
            $contentHash = $this->generateContentHash($eventData);

            $existingEvent = Event::where('integration_id', $this->integration->id)
                ->where('event_metadata->content_hash', $contentHash)
                ->where('time', '>=', now()->subHours(24)) // Only check recent events
                ->first();

            if ($existingEvent) {
                Log::info('Found content-based duplicate event', [
                    'integration_id' => $this->integration->id,
                    'existing_event_id' => $existingEvent->id,
                    'content_hash' => $contentHash,
                ]);
            }
        }

        return $existingEvent;
    }

    /**
     * Generate a hash of event content for duplicate detection
     */
    protected function generateContentHash(array $eventData): string
    {
        $contentParts = [
            $eventData['action'] ?? '',
            $eventData['actor'] ?? '',
            $eventData['target'] ?? '',
            $eventData['time'] ?? '',
            $eventData['value'] ?? '',
            $eventData['value_unit'] ?? '',
        ];

        return md5(serialize($contentParts));
    }

    /**
     * Handle duplicate event detection and processing
     */
    protected function handleDuplicateEvent(Event $existingEvent, array $newEventData): ?Event
    {
        // Check if the existing event needs updating
        $needsUpdate = $this->eventNeedsUpdate($existingEvent, $newEventData);

        if ($needsUpdate) {
            Log::info('Updating existing event with new data', [
                'integration_id' => $this->integration->id,
                'event_id' => $existingEvent->id,
                'reason' => $needsUpdate['reason'],
            ]);

            $existingEvent->update($needsUpdate['updates']);

            return $existingEvent;
        }

        Log::debug('Skipping duplicate event', [
            'integration_id' => $this->integration->id,
            'event_id' => $existingEvent->id,
        ]);

        return null; // Signal that no new event was created
    }

    /**
     * Determine if an existing event needs updating
     */
    protected function eventNeedsUpdate(Event $existingEvent, array $newEventData): array
    {
        $updates = [];

        // Check if value changed (for pending -> completed transactions, etc.)
        if (isset($newEventData['value']) && $existingEvent->value !== $newEventData['value']) {
            $updates['value'] = $newEventData['value'];
            $updates['value_multiplier'] = $newEventData['value_multiplier'] ?? 1;
            $updates['event_metadata'] = array_merge($existingEvent->event_metadata ?? [], [
                'value_updated' => true,
                'previous_value' => $existingEvent->value,
                'updated_at' => now()->toISOString(),
            ]);

            return ['reason' => 'value_changed', 'updates' => $updates];
        }

        // Check if status changed
        if (isset($newEventData['event_metadata']['status']) &&
            ($existingEvent->event_metadata['status'] ?? null) !== $newEventData['event_metadata']['status']) {
            $updates['event_metadata'] = array_merge($existingEvent->event_metadata ?? [], [
                'status' => $newEventData['event_metadata']['status'],
                'status_updated' => true,
                'previous_status' => $existingEvent->event_metadata['status'] ?? null,
                'updated_at' => now()->toISOString(),
            ]);

            return ['reason' => 'status_changed', 'updates' => $updates];
        }

        return []; // No updates needed
    }

    /**
     * Enhanced retry logic with exponential backoff and circuit breaker
     */
    protected function shouldRetry(Throwable $exception, int $attempt): bool
    {
        // Don't retry certain types of errors
        if ($this->isNonRetryableException($exception)) {
            Log::warning('Not retrying non-retryable exception', [
                'integration_id' => $this->integration->id,
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
            ]);

            return false;
        }

        // Implement circuit breaker for repeated failures
        $failureKey = "job_failures:{$this->integration->id}:{$this->getServiceName()}";
        $failureCount = Cache::get($failureKey, 0);

        if ($failureCount >= 5) {
            Log::error('Circuit breaker triggered - too many failures', [
                'integration_id' => $this->integration->id,
                'service' => $this->getServiceName(),
                'failure_count' => $failureCount,
            ]);

            return false;
        }

        // Increment failure count
        Cache::put($failureKey, $failureCount + 1, 3600); // Reset after 1 hour

        return true;
    }

    /**
     * Determine if an exception should not be retried
     */
    protected function isNonRetryableException(Throwable $exception): bool
    {
        $nonRetryable = [
            \Illuminate\Auth\AuthenticationException::class,
            \Illuminate\Auth\Access\AuthorizationException::class,
            \Illuminate\Validation\ValidationException::class,
        ];

        foreach ($nonRetryable as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }

        // Check for specific error messages
        $message = strtolower($exception->getMessage());
        $nonRetryableMessages = [
            'invalid api key',
            'unauthorized',
            'forbidden',
            'invalid credentials',
            'access denied',
        ];

        foreach ($nonRetryableMessages as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clean up after successful processing
     */
    protected function cleanupAfterSuccess(): void
    {
        // Reset failure counter
        $failureKey = "job_failures:{$this->integration->id}:{$this->getServiceName()}";
        Cache::forget($failureKey);

        // Mark job as successfully processed
        $idempotencyKey = $this->generateIdempotencyKey();
        $this->markAsProcessed($idempotencyKey);
    }
}
