<?php

namespace App\Integrations\Receipt;

use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

class ReceiptTimeResolver
{
    /**
     * Resolve the printed clock time in code, preserving explicit offsets.
     * The fallback is the email date, bounded by the actual import time.
     *
     * @return array{time: Carbon, metadata: array}
     */
    public function resolve(array $transaction, string $emailDate, string $fallbackTimezone, Carbon $importedAt): array
    {
        $fallback = $importedAt->copy()->utc();
        try {
            $emailTime = Carbon::parse($emailDate)->utc();
            if ($emailTime->lessThanOrEqualTo($importedAt)) {
                $fallback = $emailTime;
            }
        } catch (Throwable) {
            // A malformed sender Date header must not prevent receipt intake.
        }

        $raw = $transaction['transaction_date'] ?? null;
        $timezone = $transaction['transaction_timezone'] ?? $fallbackTimezone;
        $metadata = ['original_date' => $raw, 'timezone' => $timezone, 'source' => 'receipt'];

        try {
            if (! is_string($raw) || ! preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})?)?$/D', $raw)) {
                throw new InvalidArgumentException('missing_or_invalid_date');
            }

            $hasOffset = preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', $raw) === 1;
            if (! $hasOffset && (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true))) {
                throw new InvalidArgumentException('invalid_timezone');
            }

            $format = strlen($raw) === 10 ? '!Y-m-d' : ($hasOffset ? '!Y-m-d\TH:i:sP' : '!Y-m-d\TH:i:s');
            $date = DateTimeImmutable::createFromFormat($format, $raw, new DateTimeZone($hasOffset ? 'UTC' : $timezone));
            $errors = DateTimeImmutable::getLastErrors();
            if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
                throw new InvalidArgumentException('invalid_date');
            }
            // PHP normalizes nonexistent DST clock times; retain uncertainty instead.
            if (! $hasOffset && $date->format(strlen($raw) === 10 ? 'Y-m-d' : 'Y-m-d\TH:i:s') !== $raw) {
                throw new InvalidArgumentException('nonexistent_local_time');
            }

            if (! $hasOffset && strlen($raw) > 10) {
                $zone = $date->getTimezone();
                $transitions = $zone->getTransitions($date->getTimestamp() - 86400, $date->getTimestamp() + 86400);
                foreach ($transitions ?: [] as $transition) {
                    $other = $date->modify(($date->getOffset() - $transition['offset']) . ' seconds');
                    if ($other->getTimestamp() !== $date->getTimestamp() && $other->format('Y-m-d\TH:i:s') === $raw) {
                        throw new InvalidArgumentException('ambiguous_local_time');
                    }
                }
            }

            $time = Carbon::instance($date)->utc();
            // Allow five minutes of merchant clock skew, but not scheduled dates.
            if ($time->greaterThan($importedAt->copy()->addMinutes(5))) {
                throw new InvalidArgumentException('future_date');
            }

            $metadata['timezone'] = $date->getTimezone()->getName();

            return ['time' => $time, 'metadata' => $metadata];
        } catch (InvalidArgumentException $e) {
            $metadata['source'] = 'email_or_import';
            $metadata['needs_review'] = true;
            $metadata['reason'] = $e->getMessage();

            return ['time' => $fallback, 'metadata' => $metadata];
        }
    }
}
