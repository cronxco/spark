<?php

namespace App\Support;

/** Splits a typed tag name ("place:pub", an emoji) into its name and type. */
final class TagName
{
    /** @return array{0: string, 1: string} */
    public static function normalise(string $value, ?string $type): array
    {
        $name = trim($value);
        $detectedType = $type !== null ? trim($type) : null;

        if ($detectedType === null && preg_match('/^([A-Za-z0-9-]+)[_:](.+)$/', $name, $matches) === 1) {
            $detectedType = strtolower($matches[1]);
            $name = trim($matches[2]);
        } elseif ($detectedType !== null
            && preg_match('/^' . preg_quote($detectedType, '/') . '[_:](.+)$/i', $name, $matches) === 1) {
            $name = trim($matches[1]);
        }

        $detectedType ??= preg_match('/^\p{Extended_Pictographic}(?:[\x{FE0F}\x{FE0E}])?(?:\x{200D}\p{Extended_Pictographic}(?:[\x{FE0F}\x{FE0E}])?)*$/u', $name) === 1
            ? 'emoji'
            : 'spark';

        return [$name, $detectedType];
    }
}
