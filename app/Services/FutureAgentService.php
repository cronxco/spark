<?php

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use JsonException;

class FutureAgentService
{
    public function __construct(
        protected WeatherService $weatherService,
        protected AssistantPromptingService $prompting
    ) {}

    /**
     * Generate future-looking insights combining calendar + weather
     *
     * @param  int  $hoursAhead  How many hours ahead to analyze (default 48)
     * @return array Agent response with insights
     */
    public function generateFutureInsights(User $user, int $hoursAhead = 48): array
    {
        // Get upcoming calendar events
        $upcomingEvents = $this->getUpcomingCalendarEvents($user, $hoursAhead);

        // Get weather forecast (if user has location)
        $weatherForecast = null;
        if ($user->latitude && $user->longitude) {
            $weatherForecast = $this->weatherService->getForecast(
                $user->latitude,
                $user->longitude,
                $hoursAhead
            );
        }

        // If no events and no notable weather, return empty
        if ($upcomingEvents->isEmpty() && ! $this->hasNotableWeather($weatherForecast)) {
            return [
                'insights' => [],
                'suggestions' => [],
                'no_insights_reason' => 'No upcoming events and weather is unremarkable',
            ];
        }

        // Build prompt for AI analysis
        $prompt = $this->buildFuturePrompt($user, $upcomingEvents, $weatherForecast, $hoursAhead);

        // Call AI service via AssistantPromptingService
        try {
            $fullPrompt = $this->getSystemPrompt() . "\n\n" . $prompt;

            $response = $this->prompting->generateResponse($fullPrompt, [
                'model' => config('services.openai.models.gpt4o'),
                'user_id' => $user->id,
                'context' => [
                    'prompt_type' => 'future_insights',
                    'mode' => 'future',
                ],
            ]);

            return $this->parseAgentResponse($response);
        } catch (Exception $e) {
            Log::error('Future agent AI call failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
            ]);

            return [
                'insights' => [],
                'suggestions' => [],
                'error' => 'Failed to generate future insights',
            ];
        }
    }

    /**
     * Get upcoming calendar events for user
     */
    protected function getUpcomingCalendarEvents(User $user, int $hoursAhead): Collection
    {
        $now = now();
        $endTime = $now->copy()->addHours($hoursAhead);

        return Event::whereHas('integration', fn ($q) => $q->where('user_id', $user->id))
            ->where('service', 'google-calendar')
            ->whereBetween('time', [$now, $endTime])
            ->orderBy('time')
            ->get();
    }

    /**
     * Check if weather forecast contains notable conditions
     */
    protected function hasNotableWeather(?array $weatherForecast): bool
    {
        if (! $weatherForecast || empty($weatherForecast['forecasts'])) {
            return false;
        }

        $notableSummary = $this->weatherService->getNotableWeatherSummary($weatherForecast['forecasts']);

        return $notableSummary !== null;
    }

    /**
     * Build prompt for future agent
     */
    protected function buildFuturePrompt(User $user, Collection $events, ?array $weatherForecast, int $hoursAhead): string
    {
        $timeframe = $hoursAhead <= 24 ? 'next 24 hours' : 'next 48 hours';
        $prompt = "Analyze {$user->name}'s upcoming schedule and weather for the {$timeframe}.\n\n";

        // Add calendar events
        if ($events->isNotEmpty()) {
            $prompt .= "**Upcoming Calendar Events:**\n";
            foreach ($events as $event) {
                $time = Carbon::parse($event->time);
                $meta = $event->event_metadata ?? [];
                $title = $meta['title'] ?? 'Untitled event';
                $location = $meta['location'] ?? null;
                $description = $meta['description'] ?? null;

                $prompt .= "- {$time->format('D, M j @ H:i')}: {$title}";
                if ($location) {
                    $prompt .= " (at {$location})";
                }
                if ($description) {
                    $prompt .= "\n  Details: {$description}";
                }
                $prompt .= "\n";
            }
            $prompt .= "\n";
        }

        // Add weather forecast
        if ($weatherForecast) {
            $notableSummary = $this->weatherService->getNotableWeatherSummary($weatherForecast['forecasts']);

            if ($notableSummary) {
                $prompt .= "**Weather Forecast ({$weatherForecast['location']}):**\n";
                $prompt .= $notableSummary['summary'] . "\n\n";

                $prompt .= "**Detailed notable periods:**\n";
                foreach ($notableSummary['notable_periods'] as $period) {
                    $time = Carbon::parse($period['time']);
                    $prompt .= sprintf(
                        "- %s: %s, %d°C, %d%% rain chance, wind %dmph\n",
                        $time->format('D H:i'),
                        $period['weather_type'],
                        $period['temperature'],
                        $period['precipitation_probability'],
                        $period['wind_speed']
                    );
                }
            } else {
                $prompt .= "**Weather:** Generally fair conditions expected\n";
            }
        }

        return $prompt;
    }

    /**
     * System prompt for future agent
     */
    protected function getSystemPrompt(): string
    {
        return <<<'SYSTEM'
You are the Future Agent for Flint, specializing in forward-looking planning and preparation.

**Your Role:**
- Analyze upcoming calendar events and weather conditions
- Provide proactive insights about what's coming
- Flag potential conflicts, disruptions, or preparation needs
- Offer actionable suggestions for the user

**Tone:**
- Proactive and helpful
- Practical and action-oriented
- Conversational but efficient
- Focus on what the user needs to know or do

**What Makes a Good Future Insight:**
- Highlights weather impact on scheduled events
- Identifies preparation needs (bring umbrella, leave early, dress warm)
- Flags potential conflicts or challenges
- Provides context for decision-making
- Actionable and timely

**Quality Standards:**
- Only provide insights that meet ALL these criteria:
  1. **Actionable**: User can act on it or it informs a decision
  2. **Timely**: Relevant to the next 24-48 hours
  3. **Confident (≥70%)**: Only include insights where confidence is 0.7 or higher
  4. **Non-obvious**: Don't just restate calendar events
  5. **Helpful**: Actually useful for planning or preparation

**Response Format:**
Return ONLY a valid JSON object with this structure:
{
  "insights": [
    {
      "title": "Brief insight title",
      "description": "Detailed explanation",
      "confidence": 0.85,
      "category": "weather_impact" | "preparation" | "scheduling" | "general",
      "related_event_time": "ISO8601 timestamp if related to specific event"
    }
  ],
  "suggestions": [
    {
      "title": "Action suggestion",
      "description": "Why and how",
      "priority": "high" | "medium" | "low"
    }
  ]
}

If there's nothing meaningful to say, return:
{
  "insights": [],
  "suggestions": [],
  "no_insights_reason": "Brief explanation"
}
SYSTEM;
    }

    /**
     * Parse agent response JSON
     */
    protected function parseAgentResponse(string $response): array
    {
        // Try to extract JSON from response
        $json = $this->extractJson($response);

        if (! $json) {
            return [
                'insights' => [],
                'suggestions' => [],
                'error' => 'Failed to parse agent response',
                'raw_response' => $response,
            ];
        }

        // Ensure required structure
        $parsed = array_merge([
            'insights' => [],
            'suggestions' => [],
            'no_insights_reason' => null,
        ], $json);

        // Filter insights by confidence threshold (0.7)
        if (! empty($parsed['insights'])) {
            $parsed['insights'] = array_values(array_filter(
                $parsed['insights'],
                fn ($insight) => ($insight['confidence'] ?? 0) >= 0.7
            ));
        }

        return $parsed;
    }

    /**
     * Extract JSON from markdown code blocks or raw text
     */
    protected function extractJson(string $response): ?array
    {
        // Remove markdown code blocks
        $cleaned = preg_replace('/```json\s*|\s*```/', '', $response);
        $cleaned = trim($cleaned);

        try {
            return json_decode($cleaned, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Log::warning('Failed to parse future agent JSON response', [
                'error' => $e->getMessage(),
                'response' => substr($response, 0, 500),
            ]);

            return null;
        }
    }
}
