<?php

namespace App\Services\QuickLog;

use Illuminate\Support\Facades\Log;

/**
 * Records how well classification is doing. Never receives the typed text: only intents, confidence and sizes.
 */
class IntentTelemetry
{
    /** @param array{suggested: ?string, chosen: string, source: string, confidence: ?float, text_length: int, mentions: int} $data */
    public function record(string $userId, array $data): void
    {
        Log::info('quick_log.intent', [
            'user_id' => $userId,
            'suggested' => $data['suggested'],
            'chosen' => $data['chosen'],
            'source' => $data['source'],
            'overridden' => $data['source'] === 'override',
            'confidence_bucket' => $data['confidence'] === null ? null : round(floor($data['confidence'] * 10) / 10, 1),
            'text_length_bucket' => (int) (ceil($data['text_length'] / 20) * 20),
            'mentions' => $data['mentions'],
        ]);
    }
}
