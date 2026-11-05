<?php

namespace App\Support;

/**
 * Merchant and payee names for display. Open banking sends many names in capitals
 * ("SAINSBURY'S S/MKTS"), which read as shouting beside names the merchant cased itself. A name
 * with no lower-case letters is title-cased; any other name is left as the merchant wrote it, so
 * "eBay" and "McDonald's" survive. Short words with no vowels ("TFL", "UK", "LTD") stay in capitals,
 * since they are usually initials. Stored titles are not changed.
 */
final class MerchantName
{
    public static function display(string $name): string
    {
        $name = trim($name);
        if ($name === '' || preg_match('/\p{Ll}/u', $name) === 1 || preg_match('/\p{Lu}/u', $name) !== 1) {
            return $name;
        }

        $words = preg_split('/(\s+)/u', $name, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$name];

        return implode('', array_map(self::word(...), $words));
    }

    private static function word(string $word): string
    {
        $letters = (string) preg_replace('/[^\p{L}]/u', '', $word);
        if ($letters !== '' && mb_strlen($letters) <= 3 && preg_match('/[AEIOUY]/u', $letters) !== 1) {
            return $word;
        }

        $lower = mb_strtolower($word);

        return (string) preg_replace_callback(
            '/(^|[\-\/(&.])(\p{Ll})/u',
            fn (array $match): string => $match[1] . mb_strtoupper($match[2]),
            $lower,
        );
    }
}
