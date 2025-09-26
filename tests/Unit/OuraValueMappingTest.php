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
        $this->assertEquals(1, $plugin->mapValueForStorage('stress_day_summary', 'restful'));
        $this->assertEquals(0, $plugin->mapValueForStorage('stress_day_summary', null));
    }

    /**
     * @test
     */
    public function stress_level_mapping_for_display(): void
    {
        $plugin = new OuraPlugin;

        $this->assertEquals('Stressful', $plugin->mapValueForDisplay('stress_day_summary', 3));
        $this->assertEquals('Normal', $plugin->mapValueForDisplay('stress_day_summary', 2));
        $this->assertEquals('Restful', $plugin->mapValueForDisplay('stress_day_summary', 1));
        $this->assertEquals('No Data', $plugin->mapValueForDisplay('stress_day_summary', 0));
        $this->assertEquals('No Data', $plugin->mapValueForDisplay('stress_day_summary', null));
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
    public function unknown_mapping_key_returns_no_data_for_display(): void
    {
        $plugin = new OuraPlugin;

        $this->assertEquals('No Data', $plugin->mapValueForDisplay('unknown_mapping', 3));
    }

    /**
     * @test
     */
    public function unknown_numeric_value_returns_unknown_for_display(): void
    {
        $plugin = new OuraPlugin;

        $this->assertEquals('Unknown', $plugin->mapValueForDisplay('stress_day_summary', 99));
    }

    /**
     * @test
     */
    public function resilience_level_mapping_for_storage(): void
    {
        $plugin = new OuraPlugin;

        $this->assertEquals(5, $plugin->mapValueForStorage('resilience_level', 'excellent'));
        $this->assertEquals(4, $plugin->mapValueForStorage('resilience_level', 'solid'));
        $this->assertEquals(3, $plugin->mapValueForStorage('resilience_level', 'adequate'));
        $this->assertEquals(2, $plugin->mapValueForStorage('resilience_level', 'limited'));
        $this->assertEquals(1, $plugin->mapValueForStorage('resilience_level', 'poor'));
        $this->assertEquals(0, $plugin->mapValueForStorage('resilience_level', null));
    }

    /**
     * @test
     */
    public function resilience_level_mapping_for_display(): void
    {
        $plugin = new OuraPlugin;

        $this->assertEquals('Excellent', $plugin->mapValueForDisplay('resilience_level', 5));
        $this->assertEquals('Solid', $plugin->mapValueForDisplay('resilience_level', 4));
        $this->assertEquals('Adequate', $plugin->mapValueForDisplay('resilience_level', 3));
        $this->assertEquals('Limited', $plugin->mapValueForDisplay('resilience_level', 2));
        $this->assertEquals('Poor', $plugin->mapValueForDisplay('resilience_level', 1));
        $this->assertEquals('No Data', $plugin->mapValueForDisplay('resilience_level', 0));
        $this->assertEquals('No Data', $plugin->mapValueForDisplay('resilience_level', null));
    }
}
