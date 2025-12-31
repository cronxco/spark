<?php

namespace Tests\Unit\Services;

use App\Models\Event;
use App\Models\Integration;
use App\Models\IntegrationGroup;
use App\Models\User;
use App\Services\AIService;
use App\Services\FutureAgentService;
use App\Services\WeatherService;
use Tests\TestCase;

class FutureAgentServiceTest extends TestCase
{
    protected FutureAgentService $service;

    protected WeatherService $weatherService;

    protected AIService $aiService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->weatherService = $this->createMock(WeatherService::class);
        $this->aiService = $this->createMock(AIService::class);

        $this->service = new FutureAgentService($this->weatherService, $this->aiService);
    }

    /** @test */
    public function returns_empty_when_no_events_and_no_notable_weather()
    {
        $user = User::factory()->create([
            'latitude' => 51.5074,
            'longitude' => -0.1278,
        ]);

        $this->weatherService
            ->expects($this->once())
            ->method('getForecast')
            ->willReturn([
                'location' => 'London',
                'forecasts' => [
                    [
                        'precipitation_probability' => 10,
                        'weather_type' => 'Clear',
                        'temperature' => 15,
                    ],
                ],
            ]);

        $this->weatherService
            ->expects($this->once())
            ->method('getNotableWeatherSummary')
            ->willReturn(null);

        $result = $this->service->generateFutureInsights($user, 24);

        $this->assertIsArray($result);
        $this->assertEquals([], $result['insights']);
        $this->assertEquals([], $result['suggestions']);
        $this->assertStringContainsString('No upcoming events', $result['no_insights_reason']);
    }

    /** @test */
    public function generates_insights_when_calendar_events_exist()
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'latitude' => 51.5074,
            'longitude' => -0.1278,
        ]);

        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'google-calendar',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'google-calendar',
        ]);

        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'google-calendar',
            'time' => now()->addHours(3),
            'metadata' => [
                'title' => 'Team Meeting',
                'location' => 'Office',
            ],
        ]);

        $this->weatherService
            ->expects($this->once())
            ->method('getForecast')
            ->willReturn([
                'location' => 'London',
                'forecasts' => [],
            ]);

        $this->weatherService
            ->expects($this->once())
            ->method('getNotableWeatherSummary')
            ->willReturn(null);

        $this->aiService
            ->expects($this->once())
            ->method('chat')
            ->willReturn(json_encode([
                'insights' => [
                    [
                        'title' => 'Team meeting this afternoon',
                        'description' => 'You have a team meeting at the office in 3 hours',
                        'confidence' => 0.9,
                        'category' => 'scheduling',
                    ],
                ],
                'suggestions' => [],
            ]));

        $result = $this->service->generateFutureInsights($user, 24);

        $this->assertIsArray($result);
        $this->assertCount(1, $result['insights']);
        $this->assertEquals('Team meeting this afternoon', $result['insights'][0]['title']);
    }

    /** @test */
    public function filters_insights_below_confidence_threshold()
    {
        $user = User::factory()->create([
            'latitude' => 51.5074,
            'longitude' => -0.1278,
        ]);

        $this->weatherService
            ->method('getForecast')
            ->willReturn(['location' => 'London', 'forecasts' => []]);

        $this->weatherService
            ->method('getNotableWeatherSummary')
            ->willReturn([
                'has_notable_weather' => true,
                'summary' => 'Heavy rain expected',
                'notable_periods' => [],
            ]);

        $this->aiService
            ->expects($this->once())
            ->method('chat')
            ->willReturn(json_encode([
                'insights' => [
                    [
                        'title' => 'High confidence insight',
                        'confidence' => 0.9,
                    ],
                    [
                        'title' => 'Low confidence insight',
                        'confidence' => 0.5,
                    ],
                    [
                        'title' => 'Threshold insight',
                        'confidence' => 0.7,
                    ],
                ],
            ]));

        $result = $this->service->generateFutureInsights($user, 24);

        $this->assertCount(2, $result['insights']);
        $this->assertEquals('High confidence insight', $result['insights'][0]['title']);
        $this->assertEquals('Threshold insight', $result['insights'][1]['title']);
    }

    /** @test */
    public function handles_user_without_location_gracefully()
    {
        $user = User::factory()->create([
            'latitude' => null,
            'longitude' => null,
        ]);

        $this->weatherService
            ->expects($this->never())
            ->method('getForecast');

        $result = $this->service->generateFutureInsights($user, 24);

        $this->assertIsArray($result);
        $this->assertEquals([], $result['insights']);
    }

    /** @test */
    public function includes_weather_in_prompt_when_notable()
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'latitude' => 51.5074,
            'longitude' => -0.1278,
        ]);

        $group = IntegrationGroup::factory()->create([
            'user_id' => $user->id,
            'service' => 'google-calendar',
        ]);

        $integration = Integration::factory()->create([
            'user_id' => $user->id,
            'integration_group_id' => $group->id,
            'service' => 'google-calendar',
        ]);

        Event::factory()->create([
            'integration_id' => $integration->id,
            'service' => 'google-calendar',
            'time' => now()->addHours(3),
            'metadata' => [
                'title' => 'Outdoor Run',
            ],
        ]);

        $this->weatherService
            ->method('getForecast')
            ->willReturn([
                'location' => 'London',
                'forecasts' => [
                    [
                        'time' => now()->addHours(3)->toISOString(),
                        'precipitation_probability' => 90,
                        'weather_type' => 'Heavy rain',
                        'temperature' => 10,
                        'wind_speed' => 20,
                    ],
                ],
            ]);

        $this->weatherService
            ->method('getNotableWeatherSummary')
            ->willReturn([
                'has_notable_weather' => true,
                'summary' => 'Heavy rain at 15:00 (90% chance)',
                'notable_periods' => [
                    [
                        'time' => now()->addHours(3)->toISOString(),
                        'precipitation_probability' => 90,
                        'weather_type' => 'Heavy rain',
                        'temperature' => 10,
                        'wind_speed' => 20,
                    ],
                ],
            ]);

        $this->aiService
            ->expects($this->once())
            ->method('chat')
            ->with($this->callback(function ($messages) {
                $userMessage = $messages[1]['content'];

                return str_contains($userMessage, 'Heavy rain') &&
                       str_contains($userMessage, 'Outdoor Run');
            }))
            ->willReturn(json_encode([
                'insights' => [
                    [
                        'title' => 'Heavy rain during outdoor run',
                        'description' => 'Consider rescheduling or moving indoors',
                        'confidence' => 0.95,
                        'category' => 'weather_impact',
                    ],
                ],
                'suggestions' => [
                    [
                        'title' => 'Bring rain gear or reschedule run',
                        'priority' => 'high',
                    ],
                ],
            ]));

        $result = $this->service->generateFutureInsights($user, 24);

        $this->assertCount(1, $result['insights']);
        $this->assertStringContainsString('Heavy rain', $result['insights'][0]['title']);
        $this->assertCount(1, $result['suggestions']);
    }
}
