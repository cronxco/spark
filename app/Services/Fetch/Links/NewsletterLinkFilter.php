<?php

namespace App\Services\Fetch\Links;

/**
 * Removes a newsletter's housekeeping links before anything else sees them.
 *
 * Unsubscribe and preference links are dangerous to request (following one
 * can unsubscribe the user), so they are removed deterministically here and
 * never reach Jev, the tracking-link resolver or a fetch.
 */
class NewsletterLinkFilter
{
    /**
     * Anchor text or URL wording that always marks an unsubscribe or
     * preferences link, whatever else the link says.
     */
    private const ALWAYS_DENIED = '/(unsubscribe|opt[\s_-]?out|manage[\s_-]+(your[\s_-]+)?(subscription|preferences|email)|email[\s_-]+preferences|update[\s_-]+(your[\s_-]+)?preferences|view[\s_-]?in[\s_-]?browser|webversion)/i';

    /**
     * Short housekeeping calls to action. Only applied to short anchors so a
     * story headline that happens to contain "subscribe" is kept.
     */
    private const SHORT_TEXT_DENIED = '/\b(view (this )?(email )?(in|on) (your )?(browser|web)|view online|read online|web version|forward( this)?( to a friend)?|refer( a friend)?|referral|share( this| on \w+)?|tweet this|advertise( with us)?|sponsor us|privacy( policy)?|terms( of (service|use))?|download (the|our) app|sign ?up|subscribe( now| here)?|log ?in|sign ?in|contact us|reply( to this)?)\b/i';

    private const SHORT_TEXT_WORDS = 6;

    /**
     * Whole path segments that mark housekeeping endpoints.
     */
    private const DENIED_PATH_SEGMENTS = '/(^|\/)(manage|preferences|profile|account|login|signin|subscribe|refer|referral|advertise|sponsor|privacy|terms|share)(\/|$)/i';

    /**
     * Hosts whose links are sharing, app-store or social profile links.
     *
     * @var list<string>
     */
    private const DENIED_HOSTS = [
        'twitter.com', 'x.com', 'facebook.com', 'linkedin.com', 'instagram.com', 'threads.net', 'bsky.app',
        'tiktok.com', 'wa.me', 't.me', 'apps.apple.com', 'itunes.apple.com', 'play.google.com', 'mastodon.social',
    ];

    /**
     * @param  list<LinkCandidate>  $candidates  In DOM order
     * @param  list<string>  $listUnsubscribeUrls  URLs from the List-Unsubscribe header
     * @return list<LinkCandidate> One candidate per URL, richest anchor text, DOM order
     */
    public function filter(array $candidates, array $listUnsubscribeUrls = []): array
    {
        $unsubscribe = array_flip(array_map(fn (string $url): string => UrlCanonicalizer::canonicalize($url), $listUnsubscribeUrls));
        $byUrl = [];

        foreach ($candidates as $candidate) {
            if ($this->isDenied($candidate, $unsubscribe)) {
                continue;
            }

            $identity = UrlCanonicalizer::canonicalize($candidate->url);
            $existing = $byUrl[$identity] ?? null;

            if ($existing === null) {
                $byUrl[$identity] = $candidate;
            } elseif (mb_strlen($candidate->anchorText) > mb_strlen($existing->anchorText) && ! $candidate->imageOnly) {
                $byUrl[$identity] = new LinkCandidate(
                    id: $existing->id,
                    url: $existing->url,
                    anchorText: $candidate->anchorText,
                    inHeading: $candidate->inHeading || $existing->inHeading,
                    nearTime: $candidate->nearTime || $existing->nearTime,
                    imageOnly: false,
                    landmarks: $existing->landmarks,
                    precedingHeading: $existing->precedingHeading,
                    position: $existing->position,
                    context: $candidate->context,
                    domSignature: $existing->domSignature,
                );
            }
        }

        return array_values(array_filter($byUrl, fn (LinkCandidate $candidate): bool => ! $candidate->imageOnly));
    }

    /**
     * @param  array<string, int>  $unsubscribe
     */
    private function isDenied(LinkCandidate $candidate, array $unsubscribe): bool
    {
        if (isset($unsubscribe[UrlCanonicalizer::canonicalize($candidate->url)])) {
            return true;
        }

        $path = (string) parse_url($candidate->url, PHP_URL_PATH);
        if ($path === '' || $path === '/') {
            return true;
        }

        $host = preg_replace('/^www\./', '', $candidate->host());
        foreach (self::DENIED_HOSTS as $denied) {
            if ($host === $denied || str_ends_with((string) $host, '.' . $denied)) {
                return true;
            }
        }

        if (preg_match(self::ALWAYS_DENIED, $candidate->url) === 1
            || preg_match(self::ALWAYS_DENIED, $candidate->anchorText) === 1
            || preg_match(self::DENIED_PATH_SEGMENTS, $path) === 1) {
            return true;
        }

        return str_word_count($candidate->anchorText) <= self::SHORT_TEXT_WORDS
            && preg_match(self::SHORT_TEXT_DENIED, $candidate->anchorText) === 1;
    }
}
