<?php

namespace App\Services;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CurrencyConversionService
{
    /**
     * Fallback exchange rates (updated monthly).
     * Format: FROM_TO => rate
     */
    private const FALLBACK_RATES = [
        'USD_GBP' => 0.79,
        'EUR_GBP' => 0.86,
        'GBP_USD' => 1.27,
        'GBP_EUR' => 1.17,
        'USD_EUR' => 0.92,
        'EUR_USD' => 1.09,
        'AUD_GBP' => 0.52,
        'CAD_GBP' => 0.58,
        'CHF_GBP' => 0.91,
        'JPY_GBP' => 0.0053,
        'CNY_GBP' => 0.11,
    ];

    /**
     * Currency symbol to code mapping.
     */
    private const CURRENCY_SYMBOLS = [
        '£' => 'GBP',
        '$' => 'USD',
        '€' => 'EUR',
        '¥' => 'JPY',
        'CHF' => 'CHF',
        'A$' => 'AUD',
        'C$' => 'CAD',
    ];

    /**
     * Convert an amount from one currency to another.
     *
     * @param  int  $amount  Amount in smallest unit (pence/cents)
     * @param  string  $fromCurrency  Source currency code
     * @param  string  $toCurrency  Target currency code
     * @param  Carbon|null  $date  Date for historical rates (null = today)
     * @return int Converted amount in smallest unit
     *
     * @throws Exception If conversion fails and fallback is disabled
     */
    public function convert(
        int $amount,
        string $fromCurrency,
        string $toCurrency,
        ?Carbon $date = null
    ): int {
        // Normalize currency codes
        $fromCurrency = $this->normalizeCurrency($fromCurrency);
        $toCurrency = $this->normalizeCurrency($toCurrency);

        // No conversion needed for same currency
        if (! $this->needsConversion($fromCurrency, $toCurrency)) {
            return $amount;
        }

        // Get exchange rate
        $rate = $this->getRate($fromCurrency, $toCurrency, $date);

        // Convert and round to nearest integer
        return (int) round($amount * $rate);
    }

    /**
     * Get exchange rate between two currencies.
     *
     * @param  string  $fromCurrency  Source currency code
     * @param  string  $toCurrency  Target currency code
     * @param  Carbon|null  $date  Date for historical rates (null = today)
     * @return float Exchange rate
     *
     * @throws Exception If rate cannot be fetched and fallback is disabled
     */
    public function getRate(
        string $fromCurrency,
        string $toCurrency,
        ?Carbon $date = null
    ): float {
        // Check API key is configured before proceeding
        $apiKey = config('services.currency.api_key');
        if (empty($apiKey)) {
            throw new Exception('Currency API key not configured');
        }

        // Normalize currency codes
        $fromCurrency = $this->normalizeCurrency($fromCurrency);
        $toCurrency = $this->normalizeCurrency($toCurrency);

        // Same currency = rate of 1.0
        if (! $this->needsConversion($fromCurrency, $toCurrency)) {
            return 1.0;
        }

        // Use provided date or today
        $date = $date ?? now();

        // Try to fetch from cache or API
        try {
            return $this->fetchRate($fromCurrency, $toCurrency, $date);
        } catch (Exception $e) {
            Log::warning('Currency rate fetch failed, trying fallback', [
                'from' => $fromCurrency,
                'to' => $toCurrency,
                'date' => $date->toDateString(),
                'error' => $e->getMessage(),
            ]);

            // Try fallback rate if enabled
            if (config('services.currency.fallback_enabled', true)) {
                $fallbackRate = $this->getFallbackRate($fromCurrency, $toCurrency);
                if ($fallbackRate !== null) {
                    return $fallbackRate;
                }
            }

            // Re-throw if no fallback available
            throw $e;
        }
    }

    /**
     * Normalize currency code (handles symbols and case variations).
     *
     * @param  string  $currency  Currency code or symbol
     * @return string Normalized 3-letter currency code
     */
    public function normalizeCurrency(string $currency): string
    {
        // Trim whitespace
        $currency = trim($currency);

        // Check if it's a symbol
        if (isset(self::CURRENCY_SYMBOLS[$currency])) {
            return self::CURRENCY_SYMBOLS[$currency];
        }

        // Convert to uppercase
        $currency = strtoupper($currency);

        // Validate it's a recognized 3-letter code
        if (strlen($currency) === 3 && ctype_alpha($currency)) {
            // Check if it's a known currency code (exists in fallback rates or is GBP/USD/EUR)
            $knownCurrencies = ['GBP', 'USD', 'EUR', 'JPY', 'CNY', 'AUD', 'CAD', 'CHF'];
            if (in_array($currency, $knownCurrencies)) {
                return $currency;
            }
        }

        // Default to GBP if unrecognized
        Log::warning('Unrecognized currency code, defaulting to GBP', [
            'input' => $currency,
        ]);

        return 'GBP';
    }

    /**
     * Check if conversion is needed between two currencies.
     *
     * @param  string  $fromCurrency  Source currency
     * @param  string  $toCurrency  Target currency
     * @return bool True if conversion is needed
     */
    public function needsConversion(string $fromCurrency, string $toCurrency): bool
    {
        $fromCurrency = $this->normalizeCurrency($fromCurrency);
        $toCurrency = $this->normalizeCurrency($toCurrency);

        return $fromCurrency !== $toCurrency;
    }

    /**
     * Fetch exchange rate from cache or API.
     *
     * @param  string  $fromCurrency  Source currency
     * @param  string  $toCurrency  Target currency
     * @param  Carbon  $date  Date for rate
     * @return float Exchange rate
     *
     * @throws Exception If API request fails
     */
    private function fetchRate(string $fromCurrency, string $toCurrency, Carbon $date): float
    {
        $cacheKey = $this->getCacheKey($fromCurrency, $toCurrency, $date);

        // Check cache first
        $cachedRate = Cache::get($cacheKey);
        if ($cachedRate !== null) {
            return (float) $cachedRate;
        }

        // Fetch from API
        $rate = $this->fetchRateFromApi($fromCurrency, $toCurrency, $date);

        // Cache the rate
        $ttl = $this->getCacheTtl($date);
        Cache::put($cacheKey, $rate, $ttl);

        return $rate;
    }

    /**
     * Fetch exchange rate from exchangerate-api.com.
     *
     * @param  string  $fromCurrency  Source currency
     * @param  string  $toCurrency  Target currency
     * @param  Carbon  $date  Date for rate
     * @return float Exchange rate
     *
     * @throws Exception If API request fails
     */
    private function fetchRateFromApi(string $fromCurrency, string $toCurrency, Carbon $date): float
    {
        $apiKey = config('services.currency.api_key');
        $apiUrl = config('services.currency.api_url', 'https://v6.exchangerate-api.com/v6');

        if (empty($apiKey)) {
            throw new Exception('Currency API key not configured');
        }

        // Determine if we need historical or current rates
        $isHistorical = $date->lt(now()->startOfDay());

        if ($isHistorical) {
            // Historical rate endpoint: /v6/{API_KEY}/history/{BASE}/{YYYY}/{MM}/{DD}
            $url = sprintf(
                '%s/%s/history/%s/%s/%s/%s',
                $apiUrl,
                $apiKey,
                $fromCurrency,
                $date->year,
                str_pad((string) $date->month, 2, '0', STR_PAD_LEFT),
                str_pad((string) $date->day, 2, '0', STR_PAD_LEFT)
            );
        } else {
            // Current rate endpoint: /v6/{API_KEY}/latest/{BASE}
            $url = sprintf('%s/%s/latest/%s', $apiUrl, $apiKey, $fromCurrency);
        }

        // Make API request
        $response = Http::timeout(10)->get($url);

        if (! $response->successful()) {
            throw new Exception(sprintf(
                'Currency API request failed: %s',
                $response->body()
            ));
        }

        $data = $response->json();

        // Check for API errors
        if (($data['result'] ?? null) !== 'success') {
            throw new Exception(sprintf(
                'Currency API returned error: %s',
                $data['error-type'] ?? 'unknown'
            ));
        }

        // Extract conversion rate
        $rates = $data['conversion_rates'] ?? [];
        if (! isset($rates[$toCurrency])) {
            throw new Exception(sprintf(
                'Currency %s not found in API response',
                $toCurrency
            ));
        }

        return (float) $rates[$toCurrency];
    }

    /**
     * Get fallback exchange rate from hardcoded table.
     *
     * @param  string  $fromCurrency  Source currency
     * @param  string  $toCurrency  Target currency
     * @return float|null Fallback rate or null if not available
     */
    private function getFallbackRate(string $fromCurrency, string $toCurrency): ?float
    {
        $key = "{$fromCurrency}_{$toCurrency}";

        // Check direct mapping
        if (isset(self::FALLBACK_RATES[$key])) {
            return self::FALLBACK_RATES[$key];
        }

        // Try reverse rate
        $reverseKey = "{$toCurrency}_{$fromCurrency}";
        if (isset(self::FALLBACK_RATES[$reverseKey])) {
            return 1.0 / self::FALLBACK_RATES[$reverseKey];
        }

        // No fallback available
        return null;
    }

    /**
     * Generate cache key for exchange rate.
     *
     * @param  string  $fromCurrency  Source currency
     * @param  string  $toCurrency  Target currency
     * @param  Carbon  $date  Date for rate
     * @return string Cache key
     */
    private function getCacheKey(string $fromCurrency, string $toCurrency, Carbon $date): string
    {
        return sprintf(
            'currency_rate:%s:%s:%s',
            $fromCurrency,
            $toCurrency,
            $date->format('Y-m-d')
        );
    }

    /**
     * Get cache TTL based on date.
     *
     * @param  Carbon  $date  Date for rate
     * @return int TTL in seconds
     */
    private function getCacheTtl(Carbon $date): int
    {
        $ttlHours = config('services.currency.cache_ttl_hours', 24);

        // Historical rates never change - cache indefinitely
        if ($date->lt(now()->startOfDay())) {
            return 60 * 60 * 24 * 365; // 1 year
        }

        // Current rates - use configured TTL
        return 60 * 60 * $ttlHours;
    }
}
