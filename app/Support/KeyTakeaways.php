<?php

namespace App\Support;

/**
 * Key takeaways from a `*_key_takeaways` summary block, as a clean list.
 *
 * The block holds a JSON array of Markdown bullets ("- **Lead:** rest"), or
 * plain prose with one bullet per line when the model ignored the format.
 * Bullet markers are stripped; inline Markdown (the bold lead) is kept for
 * the client to render.
 */
final class KeyTakeaways
{
    /**
     * @return array<int, string>|null
     */
    public static function normalise(mixed $content): ?array
    {
        if ($content === null) {
            return null;
        }

        $items = is_array($content) ? $content : json_decode((string) $content, true);

        if (! is_array($items)) {
            $items = preg_split('/\R+/', (string) $content) ?: [];
        }

        $clean = [];
        foreach ($items as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $text = trim(preg_replace('/^\s*[-*\x{2022}]\s+/u', '', (string) $item) ?? '');

            if ($text !== '') {
                $clean[] = $text;
            }
        }

        return $clean === [] ? null : $clean;
    }
}
