<?php

namespace App\Services;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WeatherService
{
    protected string $apiKey;

    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.metoffice.api_key') ?? '';
        $this->baseUrl = config('services.metoffice.base_url', 'https://data.hub.api.metoffice.gov.uk/sitespecific/v0');
    }

    /**
     * Get weather forecast for a location
     *
     * @param  int  $hoursAhead  How many hours ahead to fetch (max 168 = 7 days)
     * @return array|null Weather forecast data or null on failure
     */
    public function getForecast(float $latitude, float $longitude, int $hoursAhead = 48): ?array
    {
        if (empty($this->apiKey)) {
            Log::warning('Met Office API key not configured');

            return null;
        }

        $cacheKey = "weather:forecast:{$latitude}:{$longitude}:{$hoursAhead}";

        // Cache for 1 hour
        return Cache::remember($cacheKey, 3600, function () use ($latitude, $longitude, $hoursAhead) {
            try {
                $response = Http::timeout(10)
                    ->withHeaders([
                        'apikey' => $this->apiKey,
                        'Accept' => 'application/json',
                    ])
                    ->get("{$this->baseUrl}/point/hourly", [
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'includeLocationName' => true,
                    ]);

                if (! $response->successful()) {
                    Log::error('Met Office API request failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return null;
                }

                $data = $response->json();

                // Extract hourly forecasts up to the requested hours
                $forecasts = $this->parseHourlyForecasts($data, $hoursAhead);

                return [
                    'location' => $data['features'][0]['properties']['location']['name'] ?? 'Unknown',
                    'forecasts' => $forecasts,
                    'fetched_at' => now()->toISOString(),
                ];
            } catch (Exception $e) {
                Log::error('Weather service error', [
                    'error' => $e->getMessage(),
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ]);

                return null;
            }
        });
    }

    /**
     * Determine if weather is "notable" (worth mentioning to user)
     */
    public function isNotableWeather(array $forecast): bool
    {
        // Notable conditions:
        // - Precipitation probability > 50%
        // - Heavy rain/snow
        // - Thunder
        // - Temperature extremes (< 2°C or > 30°C)
        // - High wind (> 25mph)
        // - Poor visibility (< 1000m)

        if (($forecast['precipitation_probability'] ?? 0) > 50) {
            return true;
        }

        if (str_contains(strtolower($forecast['weather_type'] ?? ''), 'heavy')) {
            return true;
        }

        if (str_contains(strtolower($forecast['weather_type'] ?? ''), 'thunder')) {
            return true;
        }

        $temp = $forecast['temperature'] ?? null;
        if ($temp !== null && ($temp < 2 || $temp > 30)) {
            return true;
        }

        if (($forecast['wind_speed'] ?? 0) > 25) {
            return true;
        }

        if (($forecast['visibility'] ?? PHP_INT_MAX) < 1000) {
            return true;
        }

        return false;
    }

    /**
     * Get a summary of notable weather conditions
     */
    public function getNotableWeatherSummary(array $forecasts): ?array
    {
        $notable = array_filter($forecasts, fn ($f) => $this->isNotableWeather($f));

        if (empty($notable)) {
            return null;
        }

        return [
            'has_notable_weather' => true,
            'notable_periods' => $notable,
            'summary' => $this->generateWeatherSummary($notable),
        ];
    }

    /**
     * Parse hourly forecast data from Met Office response
     */
    protected function parseHourlyForecasts(array $data, int $hoursAhead): array
    {
        $timeSeries = $data['features'][0]['properties']['timeSeries'] ?? [];
        $forecasts = [];
        $now = now();

        foreach ($timeSeries as $forecast) {
            $time = Carbon::parse($forecast['time']);

            // Only include future forecasts within the requested timeframe
            if ($time->isFuture() && $time->diffInHours($now) <= $hoursAhead) {
                $forecasts[] = [
                    'time' => $time->toISOString(),
                    'temperature' => $forecast['screenTemperature'] ?? null, // Celsius
                    'feels_like' => $forecast['feelsLikeTemperature'] ?? null,
                    'wind_speed' => $forecast['windSpeed10m'] ?? null, // mph
                    'wind_gust' => $forecast['windGustSpeed10m'] ?? null,
                    'wind_direction' => $forecast['windDirectionFrom10m'] ?? null, // degrees
                    'precipitation_rate' => $forecast['totalPrecipAmount'] ?? 0, // mm/hr
                    'precipitation_probability' => $forecast['probOfPrecipitation'] ?? 0, // percentage
                    'humidity' => $forecast['screenRelativeHumidity'] ?? null, // percentage
                    'visibility' => $forecast['visibility'] ?? null, // meters
                    'uv_index' => $forecast['uvIndex'] ?? null,
                    'weather_type' => $this->getWeatherTypeDescription($forecast['significantWeatherCode'] ?? null),
                ];
            }
        }

        return $forecasts;
    }

    /**
     * Convert Met Office weather codes to human-readable descriptions
     */
    protected function getWeatherTypeDescription(?int $code): string
    {
        if ($code === null) {
            return 'Unknown';
        }

        return match ($code) {
            0 => 'Clear night',
            1 => 'Sunny day',
            2 => 'Partly cloudy (night)',
            3 => 'Partly cloudy (day)',
            4 => 'Not used',
            5 => 'Mist',
            6 => 'Fog',
            7 => 'Cloudy',
            8 => 'Overcast',
            9 => 'Light rain shower (night)',
            10 => 'Light rain shower (day)',
            11 => 'Drizzle',
            12 => 'Light rain',
            13 => 'Heavy rain shower (night)',
            14 => 'Heavy rain shower (day)',
            15 => 'Heavy rain',
            16 => 'Sleet shower (night)',
            17 => 'Sleet shower (day)',
            18 => 'Sleet',
            19 => 'Hail shower (night)',
            20 => 'Hail shower (day)',
            21 => 'Hail',
            22 => 'Light snow shower (night)',
            23 => 'Light snow shower (day)',
            24 => 'Light snow',
            25 => 'Heavy snow shower (night)',
            26 => 'Heavy snow shower (day)',
            27 => 'Heavy snow',
            28 => 'Thunder shower (night)',
            29 => 'Thunder shower (day)',
            30 => 'Thunder',
            default => "Unknown code: {$code}",
        };
    }

    /**
     * Generate a human-readable weather summary
     */
    protected function generateWeatherSummary(array $notableForecasts): string
    {
        $conditions = [];

        foreach ($notableForecasts as $forecast) {
            $time = Carbon::parse($forecast['time']);
            $condition = $forecast['weather_type'];

            if (($forecast['precipitation_probability'] ?? 0) > 50) {
                $conditions[] = "{$condition} at {$time->format('H:i')} ({$forecast['precipitation_probability']}% chance)";
            } elseif ($forecast['temperature'] < 2) {
                $conditions[] = "Freezing conditions at {$time->format('H:i')} ({$forecast['temperature']}°C)";
            } elseif ($forecast['temperature'] > 30) {
                $conditions[] = "Hot weather at {$time->format('H:i')} ({$forecast['temperature']}°C)";
            } elseif (($forecast['wind_speed'] ?? 0) > 25) {
                $conditions[] = "High winds at {$time->format('H:i')} ({$forecast['wind_speed']}mph)";
            }
        }

        return implode('; ', array_slice($conditions, 0, 3)); // Max 3 conditions
    }
}
