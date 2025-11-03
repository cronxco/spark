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
    public static function detectPaywall(string $html, string $textContent): bool
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
     * Detect if page has robot check / CAPTCHA
     */
    public static function detectRobotCheck(string $title, string $html): bool
    {
        $robotIndicators = [
            'robot',
            'captcha',
            'verify you',
            'are you human',
            'cloudflare',
            'security check',
            'access denied',
            'bot detection',
        ];

        $lowerTitle = strtolower($title);
        $lowerHtml = strtolower($html);

        foreach ($robotIndicators as $indicator) {
            if (str_contains($lowerTitle, $indicator)) {
                return true;
            }
        }

        // Check HTML for common CAPTCHA providers
        $captchaProviders = ['recaptcha', 'hcaptcha', 'turnstile', 'cf-challenge'];
        foreach ($captchaProviders as $provider) {
            if (str_contains($lowerHtml, $provider)) {
                return true;
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

        // Check for robot detection
        if (self::detectRobotCheck($title, $html)) {
            return [
                'success' => false,
                'reason' => 'Robot check detected',
                'data' => null,
            ];
        }

        // Check for paywall BEFORE checking content length
        // This ensures paywall indicators are detected even with short content
        if (self::detectPaywall($html, $textContent)) {
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
