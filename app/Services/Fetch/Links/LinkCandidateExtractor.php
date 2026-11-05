<?php

namespace App\Services\Fetch\Links;

use Dom\Element;
use Dom\HTMLDocument;
use Throwable;

/**
 * Deterministically extracts link candidates from a rendered page or an email.
 *
 * Nothing here judges which links matter; it records enough structure
 * (landmarks, headings, DOM path, context) for the clusterer and the Jev
 * questions to make that call.
 */
class LinkCandidateExtractor
{
    /**
     * File extensions that are never articles (images, static assets).
     *
     * @var list<string>
     */
    public const EXCLUDED_EXTENSIONS = [
        'ico', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'bmp', 'tiff', 'avif',
        'css', 'js', 'woff', 'woff2', 'ttf', 'eot', 'map',
    ];

    private const MAX_CANDIDATES = 1500;

    private const SIGNATURE_DEPTH = 5;

    private const CONTEXT_LENGTH = 160;

    private const LANDMARK_TAGS = ['nav', 'footer', 'header', 'aside', 'form'];

    private const LANDMARK_ROLES = [
        'navigation' => 'nav',
        'contentinfo' => 'footer',
        'banner' => 'header',
        'complementary' => 'aside',
        'search' => 'form',
    ];

    /**
     * Class/id token patterns and the landmark each implies.
     *
     * @var array<string, string>
     */
    private const LANDMARK_TOKENS = [
        '/\b(menu|navbar|nav|navigation|breadcrumbs?)\b/' => 'nav',
        '/\b(footer|site-footer)\b/' => 'footer',
        '/\b(sidebar|side-bar|rail)\b/' => 'aside',
        '/(related|recommend|more-like|you-may|read-next|also-read|popular|trending|most-read)/' => 'related',
        '/(share|social)/' => 'share',
        '/(comment)/' => 'comments',
        '/\b(tags?|categor(y|ies)|topics?)\b/' => 'tags',
        '/(pagination|pager)/' => 'pagination',
        '/(sponsor|advert|promo|\bads?\b)/' => 'sponsored',
        '/(newsletter|signup|sign-up|subscribe)/' => 'signup',
        '/(cookie|consent)/' => 'consent',
    ];

    public function fromHtml(string $html, string $pageUrl): PageLinks
    {
        if (trim($html) === '') {
            return PageLinks::empty($pageUrl);
        }

        try {
            $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        } catch (Throwable) {
            return PageLinks::empty($pageUrl);
        }

        $baseUrl = UrlResolver::documentBase($document->querySelector('base[href]')?->getAttribute('href'), $pageUrl);
        $pageIdentity = UrlCanonicalizer::canonicalize($pageUrl);

        $candidates = [];
        $position = 0;

        foreach ($document->querySelectorAll('a[href]') as $anchor) {
            if (count($candidates) >= self::MAX_CANDIDATES) {
                break;
            }

            $position++;
            $url = UrlResolver::resolve((string) $anchor->getAttribute('href'), $baseUrl);

            if ($url === null || $this->isExcludedAsset($url) || UrlCanonicalizer::canonicalize($url) === $pageIdentity) {
                continue;
            }

            $visibleText = $this->collapse($anchor->textContent ?? '');
            $anchorText = $visibleText;
            if ($anchorText === '') {
                $anchorText = $this->collapse((string) ($anchor->getAttribute('aria-label') ?? $anchor->getAttribute('title') ?? ''));
            }

            $candidates[] = new LinkCandidate(
                id: 'l' . count($candidates),
                url: $url,
                anchorText: mb_substr($anchorText, 0, 200),
                inHeading: $anchor->closest('h1, h2, h3, h4') !== null || $anchor->querySelector('h1, h2, h3, h4') !== null,
                nearTime: $this->nearTime($anchor),
                imageOnly: $visibleText === '' && $anchor->querySelector('img, picture, svg') !== null,
                landmarks: $this->landmarks($anchor),
                precedingHeading: $this->precedingHeading($anchor),
                position: $position,
                context: $this->context($anchor),
                domSignature: $this->domSignature($anchor),
            );
        }

        return new PageLinks(
            pageUrl: $pageUrl,
            baseUrl: $baseUrl,
            title: $this->text($document->querySelector('title')),
            h1: $this->text($document->querySelector('h1')),
            ogType: $document->querySelector('meta[property="og:type"]')?->getAttribute('content'),
            candidates: $candidates,
            feedUrls: $this->feedUrls($document, $baseUrl),
            structuredItemUrls: $this->structuredItemUrls($document, $baseUrl),
        );
    }

    private function isExcludedAsset(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXCLUDED_EXTENSIONS, true);
    }

    /**
     * @return list<string>
     */
    private function landmarks(Element $anchor): array
    {
        $landmarks = [];

        for ($node = $anchor->parentElement; $node !== null; $node = $node->parentElement) {
            $tag = strtolower($node->localName);

            if (in_array($tag, self::LANDMARK_TAGS, true)) {
                $landmarks[] = $tag;
            }

            $role = strtolower((string) $node->getAttribute('role'));
            if (isset(self::LANDMARK_ROLES[$role])) {
                $landmarks[] = self::LANDMARK_ROLES[$role];
            }

            $tokens = strtolower(trim($node->getAttribute('class') . ' ' . $node->getAttribute('id')));
            if ($tokens !== '') {
                foreach (self::LANDMARK_TOKENS as $pattern => $landmark) {
                    if (preg_match($pattern, $tokens)) {
                        $landmarks[] = $landmark;
                    }
                }
            }
        }

        return array_values(array_unique($landmarks));
    }

    /**
     * Whether a `<time>` element sits in the same item as the link.
     */
    private function nearTime(Element $anchor): bool
    {
        $node = $anchor;

        for ($depth = 0; $depth < 4 && $node !== null; $depth++) {
            if ($node->querySelector('time') !== null) {
                return true;
            }

            $node = $node->parentElement;
        }

        return false;
    }

    /**
     * The nearest heading before the link's container, e.g. "Related stories".
     */
    private function precedingHeading(Element $anchor): ?string
    {
        $node = $anchor;

        for ($depth = 0; $depth < 6 && $node !== null; $depth++) {
            for ($sibling = $node->previousElementSibling; $sibling !== null; $sibling = $sibling->previousElementSibling) {
                $heading = preg_match('/^h[1-6]$/', strtolower($sibling->localName))
                    ? $sibling
                    : $sibling->querySelector('h1, h2, h3, h4, h5, h6');

                if ($heading !== null) {
                    $text = $this->collapse($heading->textContent ?? '');

                    return $text === '' ? null : mb_substr($text, 0, 80);
                }
            }

            $node = $node->parentElement;
        }

        return null;
    }

    private function context(Element $anchor): string
    {
        $node = $anchor->parentElement;

        for ($depth = 0; $depth < 4 && $node !== null; $depth++) {
            if (in_array(strtolower($node->localName), ['li', 'article', 'td', 'p', 'section', 'div'], true)) {
                break;
            }

            $node = $node->parentElement;
        }

        return mb_substr($this->collapse(($node ?? $anchor)->textContent ?? ''), 0, self::CONTEXT_LENGTH);
    }

    /**
     * Structural path of the link's ancestors (no indexes), so every item of a
     * repeated list shares one signature.
     */
    private function domSignature(Element $anchor): string
    {
        $parts = [];
        $node = $anchor->parentElement;

        for ($depth = 0; $depth < self::SIGNATURE_DEPTH && $node !== null; $depth++) {
            $parts[] = strtolower($node->localName) . $this->classSignature($node);
            $node = $node->parentElement;
        }

        return implode('<', $parts);
    }

    private function classSignature(Element $node): string
    {
        $tokens = preg_split('/\s+/', strtolower(trim((string) $node->getAttribute('class')))) ?: [];

        $tokens = array_values(array_unique(array_filter(array_map(
            fn (string $token): string => trim((string) preg_replace('/([_-]?[0-9a-f]{5,}|\d+)/', '', $token), '-_'),
            $tokens,
        ))));
        sort($tokens);

        return $tokens === [] ? '' : '.' . implode('.', array_slice($tokens, 0, 3));
    }

    /**
     * @return list<string>
     */
    private function feedUrls(HTMLDocument $document, string $baseUrl): array
    {
        $urls = [];

        foreach ($document->querySelectorAll('link[rel~="alternate"][href]') as $link) {
            $type = strtolower((string) $link->getAttribute('type'));

            if (! in_array($type, ['application/rss+xml', 'application/atom+xml', 'application/feed+json'], true)) {
                continue;
            }

            if ($url = UrlResolver::resolve((string) $link->getAttribute('href'), $baseUrl)) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Item URLs from JSON-LD ItemList / CollectionPage markup, in order.
     *
     * @return list<string>
     */
    private function structuredItemUrls(HTMLDocument $document, string $baseUrl): array
    {
        $urls = [];

        foreach ($document->querySelectorAll('script[type="application/ld+json"]') as $script) {
            $data = json_decode((string) $script->textContent, true);

            if (! is_array($data)) {
                continue;
            }

            foreach ($this->jsonLdNodes($data) as $node) {
                $types = (array) ($node['@type'] ?? []);

                if (array_intersect($types, ['ItemList', 'CollectionPage']) === []) {
                    continue;
                }

                $elements = $node['itemListElement'] ?? $node['mainEntity']['itemListElement'] ?? [];

                foreach ((array) $elements as $element) {
                    $href = is_array($element)
                        ? ($element['url'] ?? $element['item']['url'] ?? $element['item']['@id'] ?? (is_string($element['item'] ?? null) ? $element['item'] : null))
                        : (is_string($element) ? $element : null);

                    if (is_string($href) && ($url = UrlResolver::resolve($href, $baseUrl))) {
                        $urls[] = $url;
                    }
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param  array<mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function jsonLdNodes(array $data): array
    {
        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }

        if (isset($data['@graph']) && is_array($data['@graph'])) {
            return array_values(array_filter($data['@graph'], 'is_array'));
        }

        return [$data];
    }

    private function text(?Element $element): ?string
    {
        if ($element === null) {
            return null;
        }

        $text = $this->collapse($element->textContent ?? '');

        return $text === '' ? null : mb_substr($text, 0, 300);
    }

    private function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
