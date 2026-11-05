<?php

namespace App\Support;

use App\Integrations\PluginRegistry;
use App\Models\Block;
use Illuminate\Support\Str;

/**
 * The label, glyph and domain a block card shows in its eyebrow, worked out
 * once so the 60-odd layouts in `blocks/types` stop hard-coding them.
 *
 * Glyphs follow the shared design language (docs/UI and UX/DESIGN_LANGUAGE.md
 * §6): Font Awesome solid, one glyph per concept. A type without an entry
 * here takes its plugin's `getBlockTypes()` icon when that is Font Awesome,
 * and its domain's glyph otherwise.
 */
class BlockPresentation
{
    /** Domains with a `domain-*` colour token in resources/css/app.css. */
    public const DOMAINS = ['health', 'activity', 'money', 'media', 'knowledge', 'online', 'anomaly'];

    private const DOMAIN_ICONS = [
        'health' => 'fas.heart-pulse',
        'activity' => 'fas.person-running',
        'money' => 'fas.sterling-sign',
        'media' => 'fas.circle-play',
        'knowledge' => 'fas.book',
        'online' => 'fas.globe',
        'anomaly' => 'fas.triangle-exclamation',
    ];

    private const KIND_ICONS = [
        'heart_rate' => 'fas.heart',
        'energy' => 'fas.fire',
        'intensity' => 'fas.bolt',
        'duration' => 'fas.clock',
        'sleep_stage' => 'fas.moon',
        'distance' => 'fas.route',
        'biometrics' => 'fas.wave-square',
        'mental_energy' => 'fas.brain',
        'physical_energy' => 'fas.battery-three-quarters',
        'contributor' => 'fas.gauge-high',
        'exercise' => 'fas.dumbbell',
        'exercise_summary' => 'fas.dumbbell',
        'workout_metric' => 'fas.dumbbell',
        'coach_recommendation' => 'fas.chart-line',
        'album_art' => 'fas.music',
        'track_details' => 'fas.music',
        'artist' => 'fas.microphone',
        'book' => 'fas.book',
        'book_cover' => 'fas.book',
        'book_author' => 'fas.user',
        'bookmark_metadata' => 'fas.file-lines',
        'url' => 'fas.link',
        'image' => 'fas.image',
        'immich_photo' => 'fas.image',
        'cluster_summary' => 'fas.images',
        'cluster_people' => 'fas.user-group',
        'beer_details' => 'fas.beer-mug-empty',
        'beer_brewery' => 'fas.industry',
        'brewery_details' => 'fas.industry',
        'receipt_line_item' => 'fas.receipt',
        'receipt_tax_summary' => 'fas.receipt',
        'receipt_payment_method' => 'fas.credit-card',
        'bank_transfer' => 'fas.money-bill-transfer',
        'pot_transfer' => 'fas.money-bill-transfer',
        'foreign_exchange' => 'fas.money-bill-transfer',
        'virtual_card' => 'fas.credit-card',
        'merchant' => 'fas.store',
        'day_task' => 'fas.circle-check',
        'doc_task' => 'fas.circle-check',
        'event_time' => 'fas.clock',
        'event_location' => 'fas.location-dot',
        'badge_earned' => 'fas.award',
    ];

    public function __construct(
        public readonly string $label,
        public readonly string $icon,
        public readonly string $domain,
        public readonly bool $aiWritten,
        /** "Summary" or "Flint" for generated text, which says so in the eyebrow (§3.4). */
        public readonly ?string $generatedBy = null,
    ) {}

    public static function for(Block $block): self
    {
        $type = (string) $block->block_type;
        $service = $block->event?->service;
        $pluginClass = $service ? PluginRegistry::getPlugin($service) : null;
        $typeInfo = $pluginClass ? ($pluginClass::getBlockTypes()[$type] ?? []) : [];

        $domain = $pluginClass ? $pluginClass::getDomain() : 'knowledge';
        if (! in_array($domain, self::DOMAINS, true)) {
            $domain = 'knowledge';
        }

        $aiWritten = self::isAIWritten($type);
        $isFlint = str_starts_with($type, 'flint_');
        $pluginIcon = $typeInfo['icon'] ?? null;

        $icon = match (true) {
            $aiWritten || $isFlint => 'fas.wand-magic-sparkles',
            isset(self::KIND_ICONS[$type]) => self::KIND_ICONS[$type],
            is_string($pluginIcon) && Str::startsWith($pluginIcon, ['fas.', 'fab.']) => $pluginIcon,
            default => self::DOMAIN_ICONS[$domain],
        };

        $label = $typeInfo['display_name'] ?? Str::of($type)->replace('_', ' ')->ucfirst()->toString();

        $generatedBy = match (true) {
            $isFlint => 'Flint',
            $aiWritten => 'Summary',
            default => null,
        };

        return new self($label, $icon, $domain, $aiWritten, $generatedBy);
    }

    /**
     * Text a model wrote about the source rather than the source itself. The
     * same rule as iOS's `BlockKind.isAIWritten`.
     */
    public static function isAIWritten(string $blockType): bool
    {
        $type = strtolower($blockType);

        return $type === 'bookmark_summary'
            || Str::endsWith($type, ['_tldr', '_summary_paragraph', '_summary_short', '_summary_tweet', '_key_takeaways'])
            || in_array($type, ['tldr', 'key_takeaways'], true);
    }
}
