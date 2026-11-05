<?php

namespace App\Support;

use App\Models\Block;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The typed, allowlisted fields a block's card needs, for the mobile API.
 *
 * Raw block metadata carries pipeline internals (model names, line numbers,
 * document ids) and personal data the cards never show (account numbers,
 * terminal ids), so the compact resource never sends it. Each block type with
 * a custom card instead names the fields its card reads and the type each must
 * have. A value of the wrong type is dropped, so the client can trust the
 * shape. Money held in minor units is sent in major units, beside the block's
 * currency.
 */
final class BlockDetails
{
    /**
     * Field types: `string`, `int`, `number`, `bool`, `strings` (a list of
     * strings), `money` (minor units in metadata, major units out), `date`
     * (ISO 8601 out) and `last4` (the last four digits of a card).
     *
     * A key can rename its source with `out:source`.
     *
     * @var array<string, array<string, string>>
     */
    private const FIELDS = [
        // Music
        'track_details' => ['track' => 'string', 'album' => 'string', 'artists' => 'strings', 'duration_ms:duration' => 'int', 'popularity' => 'number'],
        'album_art' => ['artist' => 'string', 'track' => 'string'],
        'artist' => ['artist' => 'string', 'followers' => 'int', 'genres' => 'strings'],

        // Training (Hevy)
        'exercise' => ['exercise_name' => 'string', 'set_number' => 'int', 'reps' => 'int', 'weight' => 'number', 'unit' => 'string', 'rpe' => 'number'],
        'exercise_summary' => ['exercise_name' => 'string', 'sets_count' => 'int', 'total_volume' => 'number', 'unit' => 'string'],

        // Money
        'balance_change' => ['direction' => 'string'],
        'bank_transfer' => ['counterparty' => 'string'],
        'pot_transfer' => ['direction' => 'string', 'pot_name' => 'string'],
        'foreign_exchange' => ['rate' => 'number'],
        'merchant' => ['merchant' => 'string', 'category' => 'string', 'address' => 'string'],

        // Receipts
        'receipt_line_item' => ['sequence' => 'int', 'quantity' => 'number', 'unit' => 'string', 'unit_price' => 'money', 'category' => 'string'],
        'receipt_tax_summary' => ['subtotal' => 'money', 'tax_rate' => 'number', 'discount_total' => 'money', 'tip_amount' => 'money'],
        'receipt_payment_method' => ['payment_method' => 'string', 'card_last_4' => 'last4'],

        // Books
        'book' => ['author' => 'string', 'series_name' => 'string', 'series_number' => 'number', 'num_pages' => 'int', 'published_year' => 'int', 'average_rating' => 'number', 'user_rating' => 'number', 'current_progress' => 'number', 'date_read' => 'date'],

        // Beer
        'beer_details' => ['style' => 'string', 'abv' => 'number', 'ibu' => 'number', 'aggregate_rating' => 'number', 'review_count' => 'int', 'description' => 'string'],
        'brewery_details' => ['locality' => 'string', 'region' => 'string', 'aggregate_rating' => 'number', 'review_count' => 'int'],
        'beer_brewery' => ['venue' => 'string'],
    ];

    /** Types whose card also reads the event target's metadata, which wins over the block's. */
    private const READS_TARGET = ['book'];

    /**
     * @return array<string, mixed>|null null when the type has no details or none survive
     */
    public static function for(Block $block): ?array
    {
        $fields = self::FIELDS[$block->block_type] ?? null;
        if ($fields === null) {
            return null;
        }

        $metadata = (array) ($block->metadata ?? []);
        if (in_array($block->block_type, self::READS_TARGET, true)) {
            $metadata = array_merge($metadata, (array) ($block->event?->target?->metadata ?? []));
        }

        $multiplier = (float) ($block->value_multiplier ?: 100);
        $details = [];
        $hasMoney = false;

        foreach ($fields as $key => $type) {
            [$out, $source] = str_contains($key, ':') ? explode(':', $key, 2) : [$key, $key];
            $value = self::cast($metadata[$source] ?? null, $type, $multiplier);
            if ($value !== null) {
                $details[$out] = $value;
                $hasMoney = $hasMoney || $type === 'money';
            }
        }

        if ($hasMoney && filled($block->value_unit)) {
            $details['currency'] = strtoupper((string) $block->value_unit);
        }

        return $details === [] ? null : $details;
    }

    /**
     * The block types that have details.
     *
     * @return array<int, string>
     */
    public static function types(): array
    {
        return array_keys(self::FIELDS);
    }

    private static function cast(mixed $value, string $type, float $multiplier): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return match ($type) {
            'string' => is_scalar($value) && ! is_bool($value) ? trim((string) $value) ?: null : null,
            'int' => is_numeric($value) ? (int) $value : null,
            'number' => is_numeric($value) ? $value + 0 : null,
            'bool' => is_bool($value) ? $value : null,
            'strings' => self::strings($value),
            'money' => is_numeric($value) ? round($value / $multiplier, 2) : null,
            'date' => self::date($value),
            'last4' => is_scalar($value) && preg_match('/(\d{4})$/', (string) $value, $match) ? $match[1] : null,
            default => null,
        };
    }

    /**
     * @return array<int, string>|null
     */
    private static function strings(mixed $value): ?array
    {
        $items = collect(is_array($value) ? $value : explode(',', (string) $value))
            ->filter(fn (mixed $item): bool => is_scalar($item) && trim((string) $item) !== '')
            ->map(fn (mixed $item): string => trim((string) $item))
            ->values()
            ->all();

        return $items === [] ? null : $items;
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) && ! $value instanceof \DateTimeInterface) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}
