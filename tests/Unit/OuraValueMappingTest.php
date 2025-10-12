<?php

namespace Tests\Unit;

use App\Integrations\Oura\OuraPlugin;
use Tests\TestCase;

class OuraValueMappingTest extends TestCase
{
    /**
     * @test
     */
    public function stress_level_mapping_for_storage(): void
    {
        $plugin = new OuraPlugin;

        $this->assertEquals(3, $plugin->mapValueForStorage('stress_day_summary', 'stressful'));
        $this->assertEquals(2, $plugin->mapValueForStorage('stress_day_summary', 'normal'));
        $this->assertEquals(1, $plugin->mapValueForStorage('stress_day_summary', 'restored'));
        $this->assertEquals(0, $plugin->mapValueForStorage('stress_day_summary', null));
    }

    /**
     * @test
     */
    public function unknown_mapping_key_returns_null_for_storage(): void
    {
        $plugin = new OuraPlugin;

        $this->assertNull($plugin->mapValueForStorage('unknown_mapping', 'some_value'));
        $this->assertEquals(42.5, $plugin->mapValueForStorage('unknown_mapping', 42.5));
    }

    /**
     * @test
     */
    public function resilience_level_mapping_for_storage(): void
    {
        $plugin = new OuraPlugin;

        $this->assertEquals(5, $plugin->mapValueForStorage('resilience_level', 'exceptional'));
        $this->assertEquals(4, $plugin->mapValueForStorage('resilience_level', 'strong'));
        $this->assertEquals(3, $plugin->mapValueForStorage('resilience_level', 'solid'));
        $this->assertEquals(2, $plugin->mapValueForStorage('resilience_level', 'adequate'));
        $this->assertEquals(1, $plugin->mapValueForStorage('resilience_level', 'limited'));
        $this->assertEquals(0, $plugin->mapValueForStorage('resilience_level', null));
    }
}
