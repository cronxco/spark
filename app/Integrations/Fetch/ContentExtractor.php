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
     */
    public static function detectPaywall(string $html): bool
    {
        $paywallIndicators = [
            'class="paywall"',
            'id="paywall"',
            'data-paywall',
            'subscribe to continue reading',
            'sign up to read',
            'create a free account',
            'subscription required',
            'subscribers only',
            'become a member',
            'this article is exclusive',
        ];

        $lowerHtml = strtolower($html);
        foreach ($paywallIndicators as $indicator) {
            if (str_contains($lowerHtml, strtolower($indicator))) {
                return true;
            }
        }

        return false;
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
        if (self::detectPaywall($html)) {
            return [
                'success' => false,
                'reason' => 'Paywall detected',
                'data' => null,
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
