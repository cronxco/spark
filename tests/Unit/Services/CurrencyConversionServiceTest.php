<?php

namespace Tests\Unit\Services;

use App\Services\CurrencyConversionService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CurrencyConversionServiceTest extends TestCase
{
    private CurrencyConversionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CurrencyConversionService;

        // Clear cache before each test
        Cache::flush();

        // Set config values
        config([
            'services.currency.api_key' => 'test_api_key',
            'services.currency.api_url' => 'https://v6.exchangerate-api.com/v6',
            'services.currency.base_currency' => 'GBP',
            'services.currency.cache_ttl_hours' => 24,
            'services.currency.fallback_enabled' => true,
        ]);
    }

    /** @test */
    public function it_converts_usd_to_gbp(): void
    {
        // Mock API response
        Http::fake([
            'v6.exchangerate-api.com/*' => Http::response([
                'result' => 'success',
                'conversion_rates' => [
                    'GBP' => 0.79,
                ],
            ]),
        ]);

        $result = $this->service->convert(1000, 'USD', 'GBP');

        $this->assertEquals(790, $result);
    }

    /** @test */
    public function it_converts_eur_to_gbp(): void
    {
        Http::fake([
            'v6.exchangerate-api.com/*' => Http::response([
                'result' => 'success',
                'conversion_rates' => [
                    'GBP' => 0.86,
                ],
            ]),
        ]);

        $result = $this->service->convert(1000, 'EUR', 'GBP');

        $this->assertEquals(860, $result);
    }

    /** @test */
    public function it_returns_same_amount_for_same_currency(): void
    {
        $result = $this->service->convert(1000, 'GBP', 'GBP');

        $this->assertEquals(1000, $result);

        // Should not make any API calls
        Http::assertNothingSent();
    }

    /** @test */
    public function it_normalizes_currency_symbols(): void
    {
        $this->assertEquals('GBP', $this->service->normalizeCurrency('£'));
        $this->assertEquals('USD', $this->service->normalizeCurrency('$'));
        $this->assertEquals('EUR', $this->service->normalizeCurrency('€'));
        $this->assertEquals('JPY', $this->service->normalizeCurrency('¥'));
    }

    /** @test */
    public function it_normalizes_currency_codes(): void
    {
        $this->assertEquals('GBP', $this->service->normalizeCurrency('gbp'));
        $this->assertEquals('USD', $this->service->normalizeCurrency('usd'));
        $this->assertEquals('EUR', $this->service->normalizeCurrency('eur'));
        $this->assertEquals('GBP', $this->service->normalizeCurrency('GBP'));
    }

    /** @test */
    public function it_checks_if_conversion_is_needed(): void
    {
        $this->assertFalse($this->service->needsConversion('GBP', 'GBP'));
        $this->assertFalse($this->service->needsConversion('gbp', 'GBP'));
        $this->assertFalse($this->service->needsConversion('£', 'GBP'));

        $this->assertTrue($this->service->needsConversion('USD', 'GBP'));
        $this->assertTrue($this->service->needsConversion('EUR', 'GBP'));
    }

    /** @test */
    public function it_caches_exchange_rates(): void
    {
        Http::fake([
            'v6.exchangerate-api.com/*' => Http::response([
                'result' => 'success',
                'conversion_rates' => [
                    'GBP' => 0.79,
                ],
            ]),
        ]);

        // First call - should hit API
        $this->service->getRate('USD', 'GBP');
        Http::assertSentCount(1);

        // Second call - should use cache
        $this->service->getRate('USD', 'GBP');
        Http::assertSentCount(1); // Still only 1 request
    }

    /** @test */
    public function it_fetches_historical_rates(): void
    {
        $historicalDate = Carbon::parse('2025-01-15');

        Http::fake([
            'v6.exchangerate-api.com/*/history/USD/2025/01/15' => Http::response([
                'result' => 'success',
                'conversion_rates' => [
                    'GBP' => 0.80,
                ],
            ]),
        ]);

        $rate = $this->service->getRate('USD', 'GBP', $historicalDate);

        $this->assertEquals(0.80, $rate);

        // Verify correct endpoint was called
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'history/USD/2025/01/15');
        });
    }

    /** @test */
    public function it_uses_fallback_rates_when_api_fails(): void
    {
        Http::fake([
            'v6.exchangerate-api.com/*' => Http::response([], 500),
        ]);

        // Should use fallback rate instead of throwing exception
        $rate = $this->service->getRate('USD', 'GBP');

        $this->assertEquals(0.79, $rate); // Fallback rate
    }

    /** @test */
    public function it_uses_reverse_fallback_rates(): void
    {
        Http::fake([
            'v6.exchangerate-api.com/*' => Http::response([], 500),
        ]);

        // GBP_USD not in fallback table, but USD_GBP is (0.79)
        // So GBP_USD should be 1 / 0.79 ≈ 1.27
        $rate = $this->service->getRate('GBP', 'USD');

        $this->assertEqualsWithDelta(1.27, $rate, 0.01);
    }

    /** @test */
    public function it_throws_exception_when_api_fails_and_no_fallback(): void
    {
        config(['services.currency.fallback_enabled' => false]);

        Http::fake([
            'v6.exchangerate-api.com/*' => Http::response([], 500),
        ]);

        $this->expectException(Exception::class);

        $this->service->getRate('USD', 'GBP');
    }

    /** @test */
    public function it_throws_exception_when_api_key_not_configured(): void
    {
        config(['services.currency.api_key' => null]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Currency API key not configured');

        $this->service->getRate('USD', 'GBP');
    }

    /** @test */
    public function it_handles_api_error_responses(): void
    {
        Http::fake([
            'v6.exchangerate-api.com/*' => Http::response([
                'result' => 'error',
                'error-type' => 'invalid-key',
            ]),
        ]);

        // Should fall back to hardcoded rates
        $rate = $this->service->getRate('USD', 'GBP');

        $this->assertEquals(0.79, $rate);
    }

    /** @test */
    public function it_handles_missing_currency_in_response(): void
    {
        Http::fake([
            'v6.exchangerate-api.com/*' => Http::response([
                'result' => 'success',
                'conversion_rates' => [
                    'EUR' => 1.17,
                    // GBP is missing
                ],
            ]),
        ]);

        // Should fall back to hardcoded rates
        $rate = $this->service->getRate('USD', 'GBP');

        $this->assertEquals(0.79, $rate);
    }

    /** @test */
    public function it_rounds_converted_amounts_correctly(): void
    {
        Http::fake([
            'v6.exchangerate-api.com/*' => Http::response([
                'result' => 'success',
                'conversion_rates' => [
                    'GBP' => 0.793456,
                ],
            ]),
        ]);

        // 1000 * 0.793456 = 793.456, should round to 793
        $result = $this->service->convert(1000, 'USD', 'GBP');
        $this->assertEquals(793, $result);

        // 1001 * 0.793456 = 794.249, should round to 794
        $result = $this->service->convert(1001, 'USD', 'GBP');
        $this->assertEquals(794, $result);
    }

    /** @test */
    public function it_caches_historical_rates_indefinitely(): void
    {
        $historicalDate = Carbon::parse('2025-01-15');

        Http::fake([
            'v6.exchangerate-api.com/*' => Http::response([
                'result' => 'success',
                'conversion_rates' => [
                    'GBP' => 0.80,
                ],
            ]),
        ]);

        // Fetch historical rate
        $this->service->getRate('USD', 'GBP', $historicalDate);

        // Check it's cached with long TTL
        $cacheKey = 'currency_rate:USD:GBP:2025-01-15';
        $this->assertTrue(Cache::has($cacheKey));

        // Cached value should be the rate
        $this->assertEquals(0.80, Cache::get($cacheKey));
    }

    /** @test */
    public function it_handles_whitespace_in_currency_codes(): void
    {
        $this->assertEquals('GBP', $this->service->normalizeCurrency('  gbp  '));
        $this->assertEquals('USD', $this->service->normalizeCurrency(' usd '));
    }

    /** @test */
    public function it_defaults_to_gbp_for_unrecognized_currency(): void
    {
        $this->assertEquals('GBP', $this->service->normalizeCurrency('XXX'));
        $this->assertEquals('GBP', $this->service->normalizeCurrency('invalid'));
        $this->assertEquals('GBP', $this->service->normalizeCurrency('123'));
    }
}
