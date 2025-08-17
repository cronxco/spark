<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTokenUITest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function user_can_access_api_tokens_page()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('/settings/api-tokens');

        $response->assertStatus(200);
        $response->assertSee('API Tokens');
        $response->assertSee('Create New Token');
    }

    /**
     * @test
     */
    public function user_can_see_api_tokens_ui_elements()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('/settings/api-tokens');

        $response->assertStatus(200);
        $response->assertSee('Create New Token');
        $response->assertSee('Your API Tokens');
        $response->assertSee('Name');
        $response->assertSee('Create Token');
    }

    /**
     * @test
     */
    public function guest_cannot_access_api_tokens_page()
    {
        $response = $this->get('/settings/api-tokens');

        $response->assertRedirect('/login');
    }
}
