<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_appearance_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('/settings/appearance');

        $response->assertStatus(200);
    }

    public function test_appearance_page_contains_theme_options(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('/settings/appearance');

        $response->assertStatus(200);
        $response->assertSee('Appearance');
        $response->assertSee('Light');
        $response->assertSee('Dark');
        $response->assertSee('System');
    }

    public function test_appearance_page_requires_authentication(): void
    {
        $response = $this->get('/settings/appearance');

        $response->assertRedirect('/login');
    }

    public function test_appearance_component_renders(): void
    {
        $user = User::factory()->create();

        $component = Volt::actingAs($user)
            ->test('settings.appearance');

        $component->assertStatus(200);
    }

    public function test_appearance_page_contains_settings_heading(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('/settings/appearance');

        $response->assertStatus(200);
        // Check that the subheading is present
        $response->assertSee('Update the appearance settings for your account');
    }
}
