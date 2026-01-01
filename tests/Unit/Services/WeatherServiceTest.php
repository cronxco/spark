<?php

namespace Tests\Unit\Services;

use App\Services\WeatherService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WeatherServiceTest extends TestCase
{
    protected WeatherService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.metoffice.api_key' => 'test-api-key',
            'services.metoffice.base_url' => 'https://api.test.metoffice.gov.uk',
        ]);

        $this->service = new WeatherService;
    }

    /** @test */
    public function returns_null_when_api_key_not_configured()
    {
        config(['services.metoffice.api_key' => '']);
        $service = new WeatherService;

        $result = $service->getForecast(51.5074, -0.1278, 24);

        $this->assertNull($result);
    }

    /** @test */
    public function fetches_weather_forecast_successfully()
    {
        Http::fake([
            '*' => Http::response([
                'features' => [
                    [
                        'properties' => [
                            'location' => ['name' => 'London'],
                            'timeSeries' => [
                                [
                                    'time' => now()->addHours(1)->toISOString(),
                                    'screenTemperature' => 15.0,
                                    'feelsLikeTemperature' => 13.0,
                                    'windSpeed10m' => 12,
                                    'windGustSpeed10m' => 18,
                                    'windDirectionFrom10m' => 180,
                                    'totalPrecipAmount' => 0.1,
                                    'probOfPrecipitation' => 20,
                                    'screenRelativeHumidity' => 70,
                                    'visibility' => 10000,
                                    'uvIndex' => 3,
                                    'significantWeatherCode' => 3,
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = $this->service->getForecast(51.5074, -0.1278, 24);

        $this->assertIsArray($result);
        $this->assertEquals('London', $result['location']);
        $this->assertArrayHasKey('forecasts', $result);
        $this->assertCount(1, $result['forecasts']);
        $this->assertEquals(15.0, $result['forecasts'][0]['temperature']);
        $this->assertEquals('Partly cloudy (day)', $result['forecasts'][0]['weather_type']);
    }

    /** @test */
    public function caches_forecast_for_one_hour()
    {
        Cache::shouldReceive('remember')
            ->once()
            ->with(
                'weather:forecast:51.5074:-0.1278:24',
                3600,
                Mockery::type('Closure')
            )
            ->andReturn(['cached' => true]);

        $result = $this->service->getForecast(51.5074, -0.1278, 24);

        $this->assertEquals(['cached' => true], $result);
    }

    /** @test */
    public function detects_notable_weather_with_high_precipitation()
    {
        $forecast = [
            'precipitation_probability' => 80,
            'weather_type' => 'Heavy rain',
        ];

        $result = $this->service->isNotableWeather($forecast);

        $this->assertTrue($result);
    }

    /** @test */
    public function detects_notable_weather_with_heavy_conditions()
    {
        $forecast = [
            'precipitation_probability' => 30,
            'weather_type' => 'Heavy snow',
        ];

        $result = $this->service->isNotableWeather($forecast);

        $this->assertTrue($result);
    }

    /** @test */
    public function detects_notable_weather_with_temperature_extremes()
    {
        $forecast = [
            'precipitation_probability' => 10,
            'weather_type' => 'Clear',
            'temperature' => 35,
        ];

        $result = $this->service->isNotableWeather($forecast);

        $this->assertTrue($result);
    }

    /** @test */
    public function does_not_flag_normal_weather_as_notable()
    {
        $forecast = [
            'precipitation_probability' => 20,
            'weather_type' => 'Partly cloudy',
            'temperature' => 15,
            'wind_speed' => 10,
            'visibility' => 10000,
        ];

        $result = $this->service->isNotableWeather($forecast);

        $this->assertFalse($result);
    }

    /** @test */
    public function generates_notable_weather_summary()
    {
        $forecasts = [
            [
                'time' => now()->addHours(2)->toISOString(),
                'precipitation_probability' => 80,
                'weather_type' => 'Heavy rain',
                'temperature' => 12,
                'wind_speed' => 15,
            ],
            [
                'time' => now()->addHours(5)->toISOString(),
                'precipitation_probability' => 10,
                'weather_type' => 'Clear',
                'temperature' => 15,
                'wind_speed' => 8,
            ],
        ];

        $result = $this->service->getNotableWeatherSummary($forecasts);

        $this->assertIsArray($result);
        $this->assertTrue($result['has_notable_weather']);
        $this->assertCount(1, $result['notable_periods']);
        $this->assertStringContainsString('Heavy rain', $result['summary']);
    }

    /** @test */
    public function returns_null_for_unremarkable_weather()
    {
        $forecasts = [
            [
                'time' => now()->addHours(2)->toISOString(),
                'precipitation_probability' => 10,
                'weather_type' => 'Clear',
                'temperature' => 15,
                'wind_speed' => 8,
                'visibility' => 10000,
            ],
        ];

        $result = $this->service->getNotableWeatherSummary($forecasts);

        $this->assertNull($result);
    }
}
