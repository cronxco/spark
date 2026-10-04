<?php

namespace App\Services\Ai\Knowledge;

use Illuminate\Support\Facades\Log;

/**
 * Caps how much source text is sent to the model in one request.
 *
 * Truncation silently loses content, so every trim is logged loudly enough to
 * be noticed when a source starts exceeding the window.
 */
class ContentWindow
{
    public const MAX_CHARS = 150000;

    /**
     * @param  array<string, mixed>  $logContext
     */
    public static function truncate(string $content, array $logContext = []): string
    {
        $length = mb_strlen($content);

        if ($length <= self::MAX_CHARS) {
            return $content;
        }

        $truncated = mb_substr($content, 0, self::MAX_CHARS);
        $keptLength = mb_strlen($truncated);

        Log::warning('Knowledge: content truncated for AI processing', array_merge($logContext, [
            'original_length' => $length,
            'truncated_to' => $keptLength,
            'characters_lost' => $length - $keptLength,
            'percentage_sent' => round(($keptLength / $length) * 100, 1) . '%',
        ]));

        return $truncated;
    }
}
