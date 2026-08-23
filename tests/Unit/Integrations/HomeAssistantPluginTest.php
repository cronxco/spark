<?php

namespace Tests\Unit\Integrations;

use App\Integrations\HomeAssistant\HomeAssistantPlugin;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HomeAssistantPluginTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function converts_a_watch_payload_into_the_standard_event_shape(): void
    {
        $integration = Integration::factory()->create(['service' => 'home_assistant']);
        $plugin = new HomeAssistantPlugin;

        $result = $plugin->convertData([
            'title' => 'Loki',
            'app_name' => 'Disney+',
            'media_content_type' => 'tvshow',
            'entity_id' => 'media_player.living_room_atv',
            'minutes_watched' => 15,
            'will_home' => 'true',
            'dan_home' => 'false',
        ], $integration);

        $this->assertCount(1, $result['events']);

        $event = $result['events'][0];
        $this->assertSame('media', $event['domain']);
        $this->assertSame('watched', $event['action']);
        $this->assertSame(15, $event['value']);
        $this->assertSame('minutes', $event['value_unit']);
        $this->assertSame('Loki', $event['target']['title']);
        $this->assertSame('tv_watch', $event['target']['type']);
        $this->assertSame('media', $event['target']['concept']);
        $this->assertSame('home_assistant_user', $event['actor']['type']);
        $this->assertTrue($event['event_metadata']['will_home']);
        $this->assertFalse($event['event_metadata']['dan_home']);
        $this->assertSame('media_player.living_room_atv', $event['event_metadata']['entity_id']);
    }

    #[Test]
    public function returns_no_events_when_title_is_blank(): void
    {
        $integration = Integration::factory()->create(['service' => 'home_assistant']);
        $plugin = new HomeAssistantPlugin;

        $result = $plugin->convertData(['title' => '   '], $integration);

        $this->assertSame([], $result['events']);
    }

    #[Test]
    public function defaults_minutes_watched_to_fifteen_when_missing(): void
    {
        $integration = Integration::factory()->create(['service' => 'home_assistant']);
        $plugin = new HomeAssistantPlugin;

        $result = $plugin->convertData(['title' => 'Some Film'], $integration);

        $this->assertSame(15, $result['events'][0]['value']);
    }

    #[Test]
    #[DataProvider('triStatePresenceValues')]
    public function normalizes_various_presence_value_formats(mixed $rawValue, ?bool $expected): void
    {
        $integration = Integration::factory()->create(['service' => 'home_assistant']);
        $plugin = new HomeAssistantPlugin;

        $result = $plugin->convertData([
            'title' => 'Some Film',
            'will_home' => $rawValue,
        ], $integration);

        $this->assertSame($expected, $result['events'][0]['event_metadata']['will_home']);
    }

    public static function triStatePresenceValues(): array
    {
        return [
            'boolean true' => [true, true],
            'boolean false' => [false, false],
            'string true' => ['true', true],
            'string False' => ['False', false],
            'home' => ['home', true],
            'not_home' => ['not_home', false],
            'on' => ['on', true],
            'off' => ['off', false],
            'null' => [null, null],
            'garbage' => ['garbage', null],
        ];
    }
}
