<?php

namespace App\Integrations\Fetch;

use Exception;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;
use Illuminate\Support\Facades\Log;

class ContentExtractor
{
    /**
     * Extract content from HTML using Readability
     *
     * @return array ['success' => bool, 'reason' => string|null, 'data' => array|null]
     */
    public static function extract(string $html, string $url): array
    {
        // Create Readability configuration
        $config = new Configuration([
            'FixRelativeURLs' => true,
            'SubstituteEntities' => true,
            'SummonCthulhu' => false, // Disable aggressive mode
        ]);

        try {
            $readability = new Readability($config);
            $readability->parse($html, $url);

            // Extract data
            $extracted = [
                'title' => $readability->getTitle(),
                'content' => $readability->getContent(), // HTML
                'text_content' => strip_tags($readability->getContent(), '<br>'),
                'excerpt' => $readability->getExcerpt(),
                'author' => $readability->getAuthor(),
                'image' => $readability->getImage(),
                'direction' => $readability->getDirection(), // ltr/rtl
            ];

            // Validate extracted content
            $validation = self::validate($extracted, $html);

            // Write debug file with extracted content
            self::writeDebugExtraction($url, $extracted, $validation);

            if (! $validation['success']) {
                Log::warning('Fetch: Content extraction validation failed', [
                    'url' => $url,
                    'reason' => $validation['reason'],
                    'title' => $extracted['title'],
                    'content_length' => strlen($extracted['text_content'] ?? ''),
                ]);

                return $validation;
            }

            Log::debug('Fetch: Content extracted successfully', [
                'url' => $url,
                'title' => $extracted['title'],
                'author' => $extracted['author'],
                'content_length' => strlen($extracted['text_content']),
            ]);

            return [
                'success' => true,
                'reason' => null,
                'data' => $extracted,
            ];
        } catch (ParseException $e) {
            Log::error('Fetch: Readability parse error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'reason' => 'Parse error: ' . $e->getMessage(),
                'data' => null,
            ];
        } catch (Exception $e) {
            Log::error('Fetch: Extraction error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'reason' => 'Extraction error: ' . $e->getMessage(),
                'data' => null,
            ];
        }
    }

    /**
     * Detect if content is behind a paywall
     *
     * @param  string|null  $textContent  Optional extracted text content for length-based detection
     * @return bool|array Returns true/false for simple detection, or array with details if $returnDetails is true
     */
    public static function detectPaywall(string $html, ?string $textContent = null, bool $returnDetails = false): bool|array
    {
        // HTML class/id/data attribute indicators
        $htmlIndicators = [
            'class="paywall"',
            'id="paywall"',
            'data-paywall',
            'class="subscriber-only"',
            'class="premium-content"',
            'class="locked-content"',
            'class="metered-content"',
            'data-paywall-type',
            'data-subscription',
            'class="registration-wall"',
            'id="regwall"',
        ];

        // Text content indicators
        $textIndicators = [
            'subscribe to continue reading',
            'subscribe to read',
            'sign up to read',
            'sign in to read',
            'create a free account',
            'subscription required',
            'subscribers only',
            'become a member',
            'this article is exclusive',
            'premium article',
            'premium content',
            'members-only',
            'member exclusive',
            'already a subscriber',
            'for subscribers',
            'to continue reading',
            'register to continue',
            'free trial',
            'start your subscription',
            'unlock this article',
            'get unlimited access',
            'you\'ve reached your limit',
            'article limit reached',
            'free articles remaining',
            'articles this month',
        ];

        $lowerHtml = strtolower($html);
        $detectedIndicators = [];
        $paywallType = null;

        // Check HTML indicators
        foreach ($htmlIndicators as $indicator) {
            if (str_contains($lowerHtml, strtolower($indicator))) {
                $detectedIndicators[] = $indicator;
            }
        }

        // Check text indicators
        foreach ($textIndicators as $indicator) {
            if (str_contains($lowerHtml, strtolower($indicator))) {
                $detectedIndicators[] = $indicator;
            }
        }

        // Determine paywall type if indicators found
        if (! empty($detectedIndicators)) {
            $indicatorStr = implode(' ', array_map('strtolower', $detectedIndicators));

            if (str_contains($indicatorStr, 'metered') ||
                str_contains($indicatorStr, 'limit') ||
                str_contains($indicatorStr, 'remaining') ||
                str_contains($indicatorStr, 'this month')) {
                $paywallType = 'metered';
            } elseif (str_contains($indicatorStr, 'registration') ||
                      str_contains($indicatorStr, 'register') ||
                      str_contains($indicatorStr, 'regwall') ||
                      str_contains($indicatorStr, 'sign up') ||
                      str_contains($indicatorStr, 'free account')) {
                $paywallType = 'registration';
            } elseif (str_contains($indicatorStr, 'subscriber') ||
                      str_contains($indicatorStr, 'subscription') ||
                      str_contains($indicatorStr, 'premium') ||
                      str_contains($indicatorStr, 'member')) {
                $paywallType = 'hard';
            } else {
                $paywallType = 'soft';
            }
        }

        // Content truncation detection - if extracted text is suspiciously short
        // compared to what we'd expect from a full article
        // Only flag if:
        // 1. Content is relatively short (< 300 chars)
        // 2. AND we detect soft paywall language (but not explicit paywall classes)
        // 3. AND page has article structure
        // This catches paywalls that show teasers without explicit paywall divs
        $contentTruncated = false;
        if ($textContent !== null && strlen($textContent) < 300 && empty($detectedIndicators)) {
            // Check for article structure
            $hasArticleStructure = preg_match('/<article|<main|class="article|class="post|class="entry/i', $html);

            // Check for soft paywall language that suggests truncation
            $softPaywallPhrases = [
                'to continue reading',
                'continue reading this',
                'read the full',
                'sign in to read',
                'register to continue',
                'log in to read',
                'this story continues',
            ];

            $hasSoftPaywallLanguage = false;
            foreach ($softPaywallPhrases as $phrase) {
                if (str_contains($lowerHtml, $phrase)) {
                    $hasSoftPaywallLanguage = true;
                    break;
                }
            }

            // Only flag as truncated if we have both signals
            if ($hasArticleStructure && $hasSoftPaywallLanguage) {
                $contentTruncated = true;
                $paywallType = $paywallType ?? 'truncated';
            }
        }

        $isPaywall = ! empty($detectedIndicators) || $contentTruncated;

        if ($returnDetails) {
            return [
                'detected' => $isPaywall,
                'type' => $paywallType,
                'indicators' => $detectedIndicators,
                'truncated' => $contentTruncated,
            ];
        }

        return $isPaywall;
    }

    /**
     * Detect if page has robot check / CAPTCHA that's actually blocking content
     *
     * @param  string  $title  Page title
     * @param  string  $html  Raw HTML
     * @param  string|null  $extractedContent  Successfully extracted text content
     */
    public static function detectRobotCheck(string $title, string $html, ?string $extractedContent = null): bool
    {
        // Strong indicators in title are always a robot check
        $titleIndicators = [
            'just a moment',
            'security check',
            'access denied',
            'verify you',
            'are you human',
            'attention required',
        ];

        $lowerTitle = strtolower($title);
        foreach ($titleIndicators as $indicator) {
            if (str_contains($lowerTitle, $indicator)) {
                return true;
            }
        }

        // If we successfully extracted substantial content, it's not a blocking robot check
        // even if CAPTCHA libraries are present (e.g., for comment forms)
        if ($extractedContent !== null && strlen($extractedContent) >= 500) {
            return false;
        }

        // Check for active CAPTCHA challenges (not just library presence)
        $lowerHtml = strtolower($html);

        // Look for active challenge pages (Cloudflare, reCAPTCHA challenge pages)
        $activeChallengeIndicators = [
            'cf-challenge',
            'cf-browser-verification',
            'g-recaptcha-response',
            'data-hcaptcha',
            'turnstile-wrapper',
        ];

        foreach ($activeChallengeIndicators as $indicator) {
            if (str_contains($lowerHtml, $indicator)) {
                // Found challenge indicator - only flag if content is insufficient
                if ($extractedContent === null || strlen($extractedContent) < 500) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Generate content hash for change detection
     */
    public static function generateHash(string $textContent): string
    {
        // Normalize whitespace and trim
        $normalized = preg_replace('/\s+/', ' ', trim($textContent));

        return hash('sha256', $normalized);
    }

    /**
     * Write most recent content extraction to debug file
     */
    private static function writeDebugExtraction(string $url, array $extracted, array $validation): void
    {
        try {
            $logPath = storage_path('logs/fetch_extraction_last.json');

            $debugData = [
                'timestamp' => now()->toIso8601String(),
                'url' => $url,
                'validation' => [
                    'success' => $validation['success'],
                    'reason' => $validation['reason'],
                ],
                'extracted' => [
                    'title' => $extracted['title'] ?? '',
                    'author' => $extracted['author'] ?? '',
                    'excerpt' => $extracted['excerpt'] ?? '',
                    'direction' => $extracted['direction'] ?? '',
                    'image' => $extracted['image'] ?? '',
                    'content_length' => strlen($extracted['content'] ?? ''),
                    'text_content_length' => strlen($extracted['text_content'] ?? ''),
                    'content_html' => $extracted['content'] ?? '',
                    'text_content' => $extracted['text_content'] ?? '',
                ],
            ];

            file_put_contents($logPath, json_encode($debugData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (Exception $e) {
            // Silently fail - don't break the extraction process
            Log::debug('Failed to write extraction debug file', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Validate extracted content
     *
     * @return array ['success' => bool, 'reason' => string|null, 'data' => array|null]
     */
    private static function validate(array $extracted, string $html): array
    {
        $title = $extracted['title'] ?? '';
        $textContent = $extracted['text_content'] ?? '';

        // Check for missing or invalid title
        if (empty($title) || $title === 'No title found') {
            return [
                'success' => false,
                'reason' => 'No title found',
                'data' => null,
            ];
        }

        // Check for short title
        if (strlen($title) < 10) {
            return [
                'success' => false,
                'reason' => "Title too short: {$title}",
                'data' => null,
            ];
        }

        // Check for robot detection (pass extracted content for context-aware detection)
        if (self::detectRobotCheck($title, $html, $textContent)) {
            return [
                'success' => false,
                'reason' => 'Robot check detected',
                'data' => null,
            ];
        }

        // Check for paywall BEFORE checking content length
        // This ensures paywall indicators are detected even with short content
        $paywallCheck = self::detectPaywall($html, $textContent, true);
        if ($paywallCheck['detected']) {
            $paywallType = $paywallCheck['type'] ?? 'unknown';

            return [
                'success' => false,
                'reason' => "Paywall detected ({$paywallType})",
                'data' => null,
                'paywall_details' => $paywallCheck,
            ];
        }

        // Check for insufficient content
        if (strlen($textContent) < 100) {
            return [
                'success' => false,
                'reason' => 'Insufficient content (< 100 chars)',
                'data' => null,
            ];
        }

        return [
            'success' => true,
            'reason' => null,
            'data' => $extracted,
        ];
    }
}
